<?php
declare(strict_types=1);

// Global HTTP listeners remain registered; the global listener separately
// excludes merchantapi before it reads request or response bodies.
return ['listen' => []];
