# Project Overview

## What Is Strategic Commander?

Strategic Commander is a turn-based asynchronous space strategy game for iPhone. Players build fleets, colonize planets, and compete to dominate a procedurally generated galaxy. Games are played asynchronously — each player takes their turn on their own device at their own pace, then the game passes to the next player.

---

## Backend Scope

The backend supports **async multiplayer only**. Pass-and-play games are entirely local on the client and never touch the backend.

The backend is responsible for:

| Responsibility | Details |
|----------------|---------|
| User accounts | Username + password registration, persistent Sanctum token auth |
| Friends system | Search users, send/accept/decline friend requests |
| Game management | Create games, invite friends, track game status |
| Game state persistence | Store and serve the full `GameState` JSON per game |
| Turn management | Mid-turn saves, turn submission, turn abandonment |
| Game initialization | Run the TypeScript game engine (via Node.js CLI) to generate the initial map and state |
| Push notifications | Turn alerts, game invites, game outcomes via Expo Push API |

---

## What the Backend Does NOT Do

- **No game logic.** The client (TypeScript engine) computes all game state. The backend stores and serves state — it never re-runs game rules.
- **No AI.** AI turns are resolved client-side.
- **No real-time.** No WebSockets. No broadcasting. Pure request/response.
- **No pass-and-play.** Local games never hit the API.
- **No email.** No OAuth. Username + password only.

---

## Tech Stack Summary

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 11 (PHP 8.2+) |
| Auth | Laravel Sanctum |
| Database | MySQL 8+ |
| Push | Expo Push API |
| Game init | Node.js CLI (TypeScript engine, compiled) |

---

## Repository Structure

```
backend/
  app/              — Laravel application code
  database/         — Migrations
  engine/           — Compiled Node.js game engine CLI
  routes/api.php    — All API routes
  docs/             — This documentation
```

---

## Source of Truth

`docs/backend-build-instructions.md` is the authoritative specification for the entire backend. All other documentation in `docs/backend/` and `docs/project/` is derived from it.
