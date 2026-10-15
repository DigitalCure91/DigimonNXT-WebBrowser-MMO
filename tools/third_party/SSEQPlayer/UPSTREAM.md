Source: https://github.com/kode54/SSEQPlayer
Commit: 77222d3657adff358fb4e610d3e56bb7ada8ec24
License: LICENSE.TXT (WTFPL v2).
Local changes: standard C++ codecvt compatibility; avoid negative reference in channel allocator; read-only loop timing telemetry on Track; bounded per-tick instruction guard to reject nonprogressing source loops.
Renderer uses original SDAT sequences, instrument banks and sample waves. No General MIDI soundfont.
