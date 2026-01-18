# Multisite Expectations

AgentWP works in WordPress multisite networks, but the lifecycle hooks behave a bit differently than single-site installs. This document outlines what to expect for activation, upgrades, and uninstall.

## Activation
- Network activation is supported, but WordPress calls the activation hook only once for the current site.
- AgentWP creates default options for the activating site only. Other sites read defaults until settings are saved.
- Usage/search tables and background schedules are created lazily on each site when AgentWP runs.
- For large networks, visit each site once (or run a scripted loop) if you need tables and options pre-created everywhere.

## Upgrades
- Upgrades run per-site the next time the plugin loads on that site.
- Network admins can force upgrades across all sites by calling `AgentWP\Plugin\Upgrader::run_network_upgrades()` from a network admin context.
- Upgrade steps are idempotent, so re-running them on a site is safe.

## Uninstall
- Uninstall removes per-site options, tables, transients, scheduled hooks, and user meta.
- On multisite, the uninstall routine iterates through all sites (when multisite APIs are available) and cleans each site.
- Network-level options and site transients are removed after per-site cleanup.
