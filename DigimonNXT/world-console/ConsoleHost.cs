// Framework-only helper: compiled by Windows PowerShell 5.1 Add-Type.
// No network listeners, remote management, command execution or SQL live here.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;
using Microsoft.Win32.SafeHandles;

namespace DigimonNxt.WorldConsole {
    public static class LocalSession {
        [DllImport("kernel32.dll")] private static extern uint WTSGetActiveConsoleSessionId();
        [DllImport("wtsapi32.dll", SetLastError=true)] private static extern bool WTSQuerySessionInformation(IntPtr server, int session, int info, out IntPtr buffer, out int bytes);
        [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr memory);
        public static bool IsPhysicalConsole() {
            int current = Process.GetCurrentProcess().SessionId;
            if (!Environment.UserInteractive || current == 0 || WTSGetActiveConsoleSessionId() != (uint)current) return false;
            IntPtr buffer = IntPtr.Zero; int count;
            try { return WTSQuerySessionInformation(IntPtr.Zero,current,16,out buffer,out count) && count >= 2 && Marshal.ReadInt16(buffer) == 0; }
            finally { if (buffer != IntPtr.Zero) WTSFreeMemory(buffer); }
        }
        public static bool IsAdministrator() {
            using (WindowsIdentity identity = WindowsIdentity.GetCurrent()) return new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
        }
    }

    public static class Terminal {
        private static int row = -1;
        private static int previousWidth;
        public static string Safe(string value, int limit) {
            if (value == null) return "";
            StringBuilder result = new StringBuilder();
            foreach (char c in value) {
                UnicodeCategory category = Char.GetUnicodeCategory(c);
                if (category == UnicodeCategory.Control || category == UnicodeCategory.Format || category == UnicodeCategory.LineSeparator || category == UnicodeCategory.ParagraphSeparator) { result.Append(' '); }
                else result.Append(c);
                if (result.Length >= limit) break;
            }
            return result.ToString();
        }
        public static void Erase() {
            if (row < 0) return;
            try {
                int width = Math.Max(2, Console.BufferWidth);
                row = Math.Min(row,Console.BufferHeight - 1);
                Console.SetCursorPosition(0,row);
                Console.Write(new string(' ',Math.Min(previousWidth,width-1)));
                Console.SetCursorPosition(0,row);
            } catch (IOException) {} catch (ArgumentOutOfRangeException) {}
            row = -1;
        }
        public static void Line(string value, ConsoleColor color) {
            Erase(); ConsoleColor saved = Console.ForegroundColor;
            try { Console.ForegroundColor=color; Console.WriteLine(Safe(value,1600)); }
            finally { Console.ForegroundColor=saved; }
        }
        public static void Draw(string label, string text, int cursor, bool masked) {
            Erase();
            try {
                int width = Math.Max(12,Console.BufferWidth-1);
                label = Safe(label, Math.Max(4,width/2));
                string display = masked ? new string('*',text.Length) : Safe(text,2048);
                cursor = Math.Max(0,Math.Min(cursor,display.Length));
                int available = Math.Max(1,width-label.Length);
                int offset = Math.Max(0,cursor-available+1);
                if (offset > display.Length) offset=display.Length;
                string visible = display.Substring(offset,Math.Min(available,display.Length-offset));
                row = Console.CursorTop;
                Console.ForegroundColor = masked ? ConsoleColor.Yellow : ConsoleColor.Cyan;
                Console.Write(label);
                Console.ForegroundColor = ConsoleColor.Gray;
                Console.Write(visible);
                previousWidth=label.Length+visible.Length;
                Console.SetCursorPosition(Math.Min(width-1,label.Length+cursor-offset),row);
            } catch (IOException) { row=-1; } catch (ArgumentOutOfRangeException) { row=-1; }
        }
    }

    public sealed class TailBatch {
        public string[] Lines;
        public bool Gap;
        public bool Rotated;
        public string Error;
    }
    public sealed class FileTail : IDisposable {
        [StructLayout(LayoutKind.Sequential)] private struct FileInfo {
            public uint Attributes; public System.Runtime.InteropServices.ComTypes.FILETIME Created, Accessed, Written;
            public uint Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
        }
        [DllImport("kernel32.dll",SetLastError=true)] private static extern bool GetFileInformationByHandle(SafeFileHandle handle,out FileInfo data);
        private readonly string path;
        private string identity;
        private long offset;
        private bool initialized;
        private string pending="";
        private Decoder decoder = new UTF8Encoding(false,false).GetDecoder();
        private FileStream active;
        public FileTail(string path) { this.path=path; }
        public string Path { get { return path; } }
        private string Identity(FileStream stream) {
            FileInfo info;
            if (Environment.OSVersion.Platform == PlatformID.Win32NT && GetFileInformationByHandle(stream.SafeFileHandle,out info)) return info.Volume+":"+info.IndexHigh+":"+info.IndexLow;
            return File.GetCreationTimeUtc(path).Ticks.ToString(CultureInfo.InvariantCulture);
        }
        public void Dispose() { if(active != null) { active.Dispose(); active=null; } }
        public TailBatch Read(bool fromStart, int maxBytes) {
            TailBatch batch = new TailBatch { Lines=new string[0] };
            try {
                FileStream candidate=null;
                try { candidate=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.ReadWrite|FileShare.Delete); }
                catch(FileNotFoundException) { if(active == null)return batch; }
                catch(DirectoryNotFoundException) { if(active == null)return batch; }
                if(candidate != null) {
                    string current=Identity(candidate);
                    if(active == null) {
                        active=candidate; identity=current;
                        // An absent journal at startup can be created later: its first
                        // events must be read, rather than skipped as old history.
                        offset=initialized || fromStart ? 0 : active.Length;
                        initialized=true;
                    } else if(current != identity && offset >= active.Length) {
                        active.Dispose(); active=candidate; identity=current;
                        offset=0; pending=""; decoder.Reset(); batch.Rotated=true;
                    } else candidate.Dispose();
                }
                if(active == null)return batch;
                if(active.Length<offset) { offset=0;pending="";decoder.Reset();batch.Rotated=true; }
                active.Position=offset;
                int requested=(int)Math.Min(Math.Max(1,maxBytes),active.Length-offset);
                byte[] bytes=new byte[requested]; int total=0;
                while(total<requested) { int n=active.Read(bytes,total,requested-total);if(n==0)break;total+=n; }
                offset+=total;
                char[] chars=new char[total+4];
                int charCount=decoder.GetChars(bytes,0,total,chars,0,false);
                string chunk=pending+new string(chars,0,charCount);
                string[] parts=chunk.Split('\n');
                pending=parts[parts.Length-1];
                if(pending.Length>16384) { pending="";batch.Gap=true; }
                List<string> lines=new List<string>();
                for(int i=0;i<parts.Length-1;i++) {
                    string line=parts[i].TrimEnd('\r');
                    if(line.Length>0 && !line.StartsWith("<?php",StringComparison.Ordinal))lines.Add(line);
                }
                batch.Lines=lines.ToArray();
            } catch(IOException e) { batch.Error=e.Message; }
              catch(UnauthorizedAccessException e) { batch.Error=e.Message; }
            finally { initialized=true; }
            return batch;
        }
    }
}
