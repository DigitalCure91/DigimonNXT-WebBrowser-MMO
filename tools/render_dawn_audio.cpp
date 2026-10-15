// Offline ROM audio exporter; uses the bundled SSEQPlayer interpreter.
#include <fstream>
#include <iostream>
#include <iterator>
#include <iomanip>
#include <algorithm>
#include <cmath>
#include "third_party/SSEQPlayer/SDAT.h"
#include "third_party/SSEQPlayer/Player.h"
#include "third_party/SSEQPlayer/common.h"

int main(int argc, char **argv) {
    if (argc < 5) { std::cerr << "usage: renderer sound.sdat seq-index output.pcm output.json [start-offset] [max-seconds]\n"; return 2; }
    try {
        std::ifstream input(argv[1], std::ios::binary);
        if (!input) throw std::runtime_error("Cannot read SDAT");
        std::vector<uint8_t> bytes((std::istreambuf_iterator<char>(input)), {});
        PseudoFile file; file.data = &bytes;
        SDAT sdat(file, std::stoul(argv[2]));
        Player player; player.sampleRate = 32768; player.interpolation = INTERPOLATION_SINC;
        player.sseqVol = Cnv_Sust(sdat.sseq->info.vol);
        if (!player.Setup(sdat.sseq.get())) throw std::runtime_error("Cannot set up sequence");
        if (argc > 5) player.tracks[0].pos = sdat.sseq->data.data() + std::stoul(argv[5]);
        double maxSeconds = argc > 6 ? std::stod(argv[6]) : 240.0;
        std::ofstream output(argv[3], std::ios::binary);
        if (!output) throw std::runtime_error("Cannot write PCM");
        std::vector<uint8_t> buffer(1024 * 4);
        double finishAt = maxSeconds, loopStart = -1, loopEnd = -1;
        bool determined = false, allEnded = false;
        uint64_t sampleCount = 0, nonzero = 0, clipped = 0;
        int peak = 0;
        while (sampleCount < static_cast<uint64_t>(finishAt * player.sampleRate)) {
            unsigned count = std::min<uint64_t>(1024, static_cast<uint64_t>(finishAt * player.sampleRate) - sampleCount);
            player.GenerateSamples(buffer, 0, count);
            for (unsigned n=0; n<count*4; n+=2) {
                int v=static_cast<int16_t>(buffer[n] | (buffer[n+1]<<8));
                peak=std::max(peak,std::abs(v)); nonzero += v!=0; clipped += v==32767 || v==-32768;
            }
            output.write(reinterpret_cast<char*>(buffer.data()), count*4);
            sampleCount += count;
            if (!determined && player.secondsIntoPlayback > .1) {
                bool allComplete = true; allEnded = true;
                for (unsigned n=0; n<player.nTracks; ++n) {
                    const Track &t=player.tracks[player.trackIds[n]];
                    bool ended=t.state[TS_END];
                    if (!ended) allEnded = false;
                    if (!ended && t.exportLoopEnd < 0) allComplete = false;
                    if (t.exportLoopEnd > loopEnd) { loopStart=t.exportLoopStart; loopEnd=t.exportLoopEnd; }
                }
                if (allComplete) {
                    determined = true;
                    finishAt = allEnded ? player.secondsIntoPlayback + 2.0 : loopEnd + 0.12;
                }
            }
        }
        output.close();
        std::ofstream meta(argv[4]);
        meta << std::setprecision(10) << "{\"sample_rate\":32768,\"channels\":2,\"frames\":" << sampleCount
             << ",\"duration\":" << (sampleCount/32768.0) << ",\"loop_start\":" << loopStart
             << ",\"loop_end\":" << loopEnd << ",\"ended\":" << (allEnded?"true":"false")
             << ",\"timing_resolved\":" << (determined?"true":"false") << ",\"nonzero_samples\":" << nonzero
             << ",\"peak\":" << peak << ",\"clipped_samples\":" << clipped << "}\n";
        if (!nonzero) return 3;
    } catch(const std::exception &e) { std::cerr << e.what() << '\n'; return 1; }
    return 0;
}
