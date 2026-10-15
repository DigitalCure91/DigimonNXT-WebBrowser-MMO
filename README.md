# Digimon NXT

### Pick your partner. Build your team. Explore the Digital World.

**Digimon NXT is an open-source Digimon browser MMORPG where you explore, battle, collect and digivolve.** Hunt your favourite Digimon, discover rare Paradox forms and take your team into Battle Park. Play with other tamers or host your own world, with 1,000 AI rivals to adventure alongside.

**1,000 collectible forms · 264 Dawn maps · 3v3 battles · Play in your browser**

## 🌍 A world to explore

Travel across **264 Dawn maps** covering 30 environments. Watch wild Digimon roam, spot other tamers and follow travel gates to your next destination.

Browse the world atlas to find somewhere new, check the area's wild levels and head out. Separate paths and platforms can be reached through **Area landings**.

## 🐾 Build your dream team

There are **500 standard forms and 500 Paradox variants** to discover.

- **Scan wild Digimon** by entering battles, then turn their data into new partners at the DigiLab.
- **Train and digivolve** your favourites. Explore evolution branches, de-digivolution, mode changes and DNA evolutions.
- **Carry inherited skills forward** and shape your partners through stat training.
- **Track your collection** in the DigiDex and search your storage when it's time to change your lineup.

Your team has room for **six Digimon: three in battle and three in reserve**. Try different partners, cover your weaknesses and find the team you enjoy playing.

### Looking for something rare?

**Paradox Digimon** have their own artwork, separate scan records and boosted base stats. Their default appearance rate is **0.2% per roaming signal**, and each encounter gives only **1–3% scan**. They're a long-term hunt for collectors.

## ⚔️ Battle your way

Wild battles are **turn-based 3v3 fights**. Choose skills and targets, use attribute advantages, guard, swap partners or reach for a recovery capsule when things get rough.

Attacks come to life with movement, elemental effects, damage numbers, critical hits and healing feedback, alongside Dawn music and sound effects.

**Less downtime, more exploring:** your entire party returns to full HP and SP after a battle ends—even after a loss or disconnect. You also keep the scan data earned when the encounter started.

## 🏆 Take on Battle Park

Put your lineup to the test against other players' teams and AI rivals. Earn points, climb weekly ranks and collect Bits, EXP and medals to spend on supplies.

**How it works:** Battle Park automatically plays a 3v3 fight against the opponent's saved team. The other tamer can be offline. Your first three available partners make up your ranked lineup, so team building matters.

Watch recorded replays of new matches involving players, and follow your wins, losses and familiar opponents through **Battle History** and **Tamer Rivals**.

## 🤝 Make some connections

Add friends, send private messages, join clans and browse other tamers' profiles. Have a spare partner? List it on the **Partner Exchange** for Bits, or browse another player's collection listings for your next teammate.

The world also includes **1,000 persistent AI tamers**. They have their own teams and earn progress by exploring, fighting, scanning, training and evolving. Check **Tamer Activity** to see what they've been doing.

With the background worker running, rivals keep playing even when everyone closes their browser. The host PC and database need to stay running.

## 🎮 Your first adventure

1. **Choose your starter:** Agumon, Gabumon, Tentomon, Veemon, Guilmon or Terriermon. Pick your tamer's look from the available appearances, including 64 Dawn designs.
2. **Meet your team.** You start with your chosen level 5 partner and two companions, ready for your first 3v3 battle.
3. **Open Dawn World.** Pick an area from the atlas and move toward a wild Digimon.
4. **Select it and battle.** Click the Digimon—or press **E** for the nearest one—then choose **Battle** when you're close enough.
5. **Make a new partner.** Once a Digimon reaches **100% scan**, visit the DigiLab to materialize it. Waiting until **200%** gives it extra starting ABI, a stat used in partner progression.
6. **Keep growing.** Try new partners, unlock evolutions, fill your reserves and give Battle Park a go.

### Handy controls

| Want to… | Use… |
| --- | --- |
| Move | **WASD**, arrow keys, numpad or the on-screen pad |
| Select the nearest wild Digimon | **E** |
| Find yourself on the map | **Find my tamer** |
| Show Digimon names | **Names** toggle |
| Reach another path or platform | **Area landings** |
| Speed up a battle | **1× / 1.5× / 2×** or **Skip animation** |

Music and effects have separate volume controls. Reduced-motion options are available too.

## 🚀 Ready to play?

### Join a server

Open the server's game address in your browser, create a tamer and jump in. Your account and progress are saved on that server.

### Prefer a Windows game window?

Download the [Windows x64 client v1.1.0](https://github.com/AkumaVenom/DigimonNXT-WebBrowserMMO-/releases/tag/v1.1.0), extract the entire ZIP and run **`DigimonNXT.exe`**. Enter your server address under **Server & options**.

The client uses Microsoft Edge WebView2 and includes maps, sprites and music. It connects to a running server, which can be your own PC.

### Want your own world?

You can run the server locally and play through your browser. The source currently in this repository uses **XAMPP's Apache and MySQL/MariaDB**.

<details>
<summary><strong>Open the Windows server setup guide</strong></summary>

### What you need

- XAMPP with **PHP 8.1+** and **mysqli/mysqlnd**.
- **MySQL 8** or **MariaDB 10.4+**.
- A modern browser.

### Get it running

1. Download and extract the **server source** from the link at the top of this page.
2. Copy its **`DigimonNXT` folder** into `C:\xampp\htdocs\`. Your game should be at `C:\xampp\htdocs\DigimonNXT\`. Keep the repository's top-level `tools` folder outside the web folder.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Open [local setup](http://localhost/DigimonNXT/_setup.php) on the server PC and choose **Create / Repair Schema**.
5. Run **`tools/install_tamer_service_windows.bat`** from the extracted source and accept the Windows administrator prompt. This starts the background AI rivals and installs their startup task.
6. Open [your local game](http://localhost/DigimonNXT/), create your tamer and start playing.

The default database is `digimon_nxt`, using `root` with a blank password at `127.0.0.1:3306`. If your database settings differ, update the installed **`DigimonNXT/config/app.php`** before running setup. The default game path is `/DigimonNXT`.

**Folder tip:** this repository calls the game folder `DigimonNXT/`. Older package instructions may call the equivalent folder `public_html/`.

### Server controls

Open **`tools/start_world_server_windows.bat`** for the local administrator console. Start with `help` and `status`. It provides commands for accounts, moderation, rewards, Digimon and announcements.

Apache and MySQL must already be running. Closing this console leaves those services and the background rivals running.

| Rival task | File in `tools/` |
| --- | --- |
| Check status | `tamer_service_status_windows.bat` |
| Stop | `stop_tamer_service_windows.bat` |
| Start | `start_tamer_service_windows.bat` |

