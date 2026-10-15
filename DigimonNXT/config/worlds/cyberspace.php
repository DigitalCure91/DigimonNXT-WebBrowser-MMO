<?php
declare(strict_types=1);
// Retain the world key for existing bookmarks and durable account positions.
return json_decode((string)file_get_contents(__DIR__.'/dawn_world.json'), true, 512, JSON_THROW_ON_ERROR);
