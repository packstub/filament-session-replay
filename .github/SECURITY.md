# Security Policy

Filament Session Replay shows recordings of what people did in a panel. Please report anything that lets someone open, export or delete a recording the app's `viewSessionReplay` gate (or its `ReplaySession` policy) denies, or that records what `maskInReplay()` / `blockInReplay()` should have hidden.

If you discover a security issue, email [support@packstub.dev](mailto:support@packstub.dev) instead of using the issue tracker. We answer within a few days and credit reporters in the changelog unless they prefer otherwise.

Recording, storage and the data routes belong to [packstub/session-replay](https://github.com/packstub/session-replay); its policy covers them.
