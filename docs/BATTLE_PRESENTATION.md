# Battle animations and feedback

Wild battles show each accepted command and the enemy response in order. Physical attackers move toward their target and return; magic uses a short charge and an elemental projectile. The target reacts at contact while its HP bar changes and damage text fades beside it. Multi-hit moves show each actual hit. Critical, effective, resisted, guarded, missed and blocked labels come from the server's combat result.

Healing and capsules show HP/SP restored. Status effects, poison, guarding, reserve changes and knockouts have their own feedback. After the final action, your party restores to full HP/SP and the result screen shows the saved rewards.

## Controls

- **Speed:** choose 1x, 1.5x or 2x. The default is 1.5x; your browser remembers your choice.
- **Skip animation:** immediately shows the completed turn or match. It never changes the result or charges another action.
- **Sound:** click Enable sound if your browser requests it. Original Dawn music continues between wild commands. Music and effects have separate volume sliders; manual music selection still works.
- **Motion:** the existing Motion switch, account preferences and your device's reduced-motion setting are respected. Reduced-motion mode keeps readable fading outcome text without sprite travel or impact flashes.

Command buttons remain unavailable while their accepted turn is being shown. If a connection fails, the game checks the latest saved turn before allowing another command. If it cannot reconnect, use Refresh battle. Your accepted action is never automatically submitted a second time.

## Battle Park replays

New matches involving a human player record their actual combat events, including defenses against autonomous tamers. After a challenge, the replay starts once. Open a match in Battle History and choose Replay battle to watch it again. Replays do not spend energy or grant rewards a second time. The named left formation is the attacker and the named right formation is the defender; “You” identifies your team.

Older matches retain their text battle reports. Bot-versus-bot matches retain compact reports. Very long recorded matches have a bounded highlights sequence and a full text report; the final state always reconciles to the saved result.

## Install this update

For v0.7.1, copy the update ZIP's `public_html/` contents into your installed DigimonNXT game folder, accepting replacement of the included files. It does not include `config/app.php`, SQL or saves. Restart the existing tamer task after updating PHP, then reload the game. No database repair or reset is required for this update.

Windows client: use Reload after updating the server. Android: reconnect to your server. The content-hash manifest makes existing clients request changed scripts and styles without downloading the unchanged map, sprite and soundtrack assets again. No native-client rebuild is required.

The full-source edition defaults to a blank database password and includes all Dawn maps, collision, sprites, soundtrack, source tools and `tools/start_world_server_windows.bat`. For a new installation or an older baseline, follow START_HERE.txt.

## Implementation and limits

Combat still runs on the server under its existing transaction, CSRF and turn-token checks. The browser receives display snapshots and actual events; it does not calculate damage or award rewards. Recording does not add random rolls or change battle balance. Presentation data for wild turns is transient; only new player-involved ranked replays are saved, inside the existing match data without a schema migration.

Sprites and music are original supplied assets. Elemental visual effects are lightweight browser graphics. Dawn internal audio IDs are used with NXT scene assignments; no unsupported claim is made about exact original-game cue names. See BATTLE_AUDIO.md and TESTING.md for source verification and test scope.
