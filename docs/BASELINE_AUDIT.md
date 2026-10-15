# Baseline Reconstruction Audit

The source browser-MMO baseline contained 15,644 files. Most of that count came from legacy image content: 13,662 GIF files plus hundreds of other image files.

Digimon NXT does **not** preserve that legacy image library. The Digimon NXT package is intentionally much smaller because it contains only assets used by Digimon NXT:

- 48 normalized Digimon PNG sprites;
- 6 tamer-avatar PNGs;
- 10 Cyberspace map PNGs;
- favicon and runtime CSS/JavaScript.

The project-size reduction is therefore not a missing-assets shortcut. Runtime-required URLs are validated against the Digimon NXT asset bundle, while the MMO/database functionality is supplied by the new PHP/config/schema layers.

The source architecture was reviewed for the systems that matter to the browser MMO: account/session persistence, database installation, map movement/collision, multiplayer presence, battles, collection/team progression, social/community features, economy/exchange, events and account utilities. The accepted v0.1.1 foundation reconstructs those concepts natively around Digimon instead of retaining the legacy creature/region data model.
