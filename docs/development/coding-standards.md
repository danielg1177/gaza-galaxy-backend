# Backend Coding Standards

- Laravel API-first backend.
- Use snake_case for PHP variables.
- Keep controllers thin.
- Put game logic in services, not controllers.
- **The client computes all game state.** The backend validates identity, turn ownership, and state structure only — it never re-runs game rules.
- **Frontend submits the full resulting GameState**, not just actions. Actions are stored for audit; the resulting state is stored as the authoritative game state.
- Validate turn ownership and `turn_number`/`round_number` match on every submission. Reject stale submissions with 409.
- Never expose `in_progress_actions` to any player who is not the current player.
- Use Form Requests for validation.
- Use API Resources for responses when useful.
- Use database transactions for turn submission.
- Add tests for turn validation and privacy rules.
- Do not introduce queues, websockets, or broadcasting until needed.