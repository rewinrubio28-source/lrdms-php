# Scope Decision — LRDMS Integration Boundary

**Status:** Confirmed. **Date:** 2026-07-28
**Trigger:** Client-provided flowchart, "Legislative Process Flow" (Manila City Council).

## The client's flowchart

The client sent their office's actual drafting-to-first-reading pipeline:

1. **Author** drafts the Resolution/Ordinance.
2. **Records Section** receives and records the proposed Resolution/Ordinance.
3. **Majority Floor Leader's Office** compiles all submitted Resolutions/Ordinances for scheduling in the next Regular Session.
4. **Records Section** receives the compiled list back from the Majority Floor Leader's Office.
5. **Agenda Section** prepares the Final Agenda.
6. **Agenda Section** distributes the Final Agenda to all Councilors within one hour, via Viber and Gmail.
7. **Manila City Session Hall** — the Resolution/Ordinance is discussed during the First Reading in the Regular Session.

## Decision

**LRDMS's scope is unchanged: system of record for finalized (Enacted) legislative documents only.**

Steps 1–7 above belong to other subsystems in the Legislative Services platform — the Ordinance/Resolution Lifecycle Management System (drafting, steps 1–2), the Legislative Agenda & Calendar / Session Management System (scheduling and agenda, steps 3–6), and the Session Hall proceedings themselves (step 7). None of that pipeline is rebuilt inside LRDMS.

## What this means concretely

- LRDMS does **not** track: drafting, initial filing at Records Section, scheduling compilation, agenda prep/distribution, or the First Reading discussion itself.
- LRDMS **does** receive a document once it has cleared that pipeline and is formally enacted — either manually encoded by a Records Officer (older/physical records) or pushed in via `POST /api/upload_document.php` once the upstream Lifecycle system marks it enacted.
- The `documents.status` enum already includes `Draft` / `Submitted` / `Under Review` ahead of `Enacted`. That headroom is intentional but currently unused in the primary path — it exists so a Legislative Staff member *could* draft directly inside LRDMS later, without a schema change, if a future scope decision ever reopens this boundary.

## Why this boundary holds

- Keeps LRDMS's responsibility to one thing: the authoritative repository for finalized documents, not a second workflow engine duplicating systems that (in a real deployment, or a classmate's subsystem, in this shared thesis platform) already own drafting/scheduling/session logistics.
- Matches the original module brief's Backend Workflow Summary: ingestion begins when an upstream system pushes a file in — that's where LRDMS's responsibility starts.
- Avoids scope creep into the Agenda/Session Management side of the platform.

## Where LRDMS picks up

```
[Author] -> [Records Section] -> [Majority Floor Leader's Office] -> [Records Section]
   -> [Agenda Section] -> [Agenda Section] -> [Session Hall: First Reading]
   -> (Second/Third Reading, committee review, formal enactment — owned upstream)
   -> ENACTED  ══════════════════════════════════════════════════▶  LRDMS starts here
                                                    (api/upload_document.php, or manual encoding)
```
