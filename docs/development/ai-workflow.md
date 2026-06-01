# AI Workflow

## Roles

Sonnet is the architect, planner, reviewer, and documentation maintainer.

Cursor Auto / Haiku is the implementation model.

## Rules for Sonnet

- Do not directly implement large backend features.
- Read all relevant docs before planning.
- Keep backend architecture consistent.
- Create small implementation prompts for the coding model.
- Review code after each implementation.
- Update all affected markdown files.
- Keep `docs/project/current-state.md` accurate at all times.

## Rules for Coding Model

- Implement only the assigned task.
- Do not redesign architecture.
- Do not create unrelated abstractions.
- Do not modify unrelated files.
- Do not change database schema unless explicitly instructed.
- Do not change API response shapes unless explicitly instructed.
- After coding, summarize changed files, migrations, routes, tests, and concerns.

## Required Flow

Before coding:
1. Read `docs/project/current-state.md`.
2. Read the relevant backend system doc.
3. Read the assigned task.

After coding:
1. Update implementation summary.
2. List changed files.
3. List any assumptions.
4. Do not update high-level architecture docs unless asked.

After review:
1. Sonnet updates docs.
2. Sonnet updates `current-state.md`.
3. Sonnet updates `task-log.md`.
4. Sonnet creates the next small implementation prompt.