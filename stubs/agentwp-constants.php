<?php
/**
 * AgentWP runtime constants for PHPStan.
 *
 * These are defined at runtime via `define()` in `agentwp.php`, but PHPStan does
 * not execute plugin bootstrap code. Keeping them here prevents false positives
 * like "Constant ... not found".
 */

const AGENTWP_VERSION = '0.0.0';
const AGENTWP_PLUGIN_FILE = '';
const AGENTWP_PLUGIN_DIR = '';
const AGENTWP_PLUGIN_URL = '';

