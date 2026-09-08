# IT-faculty-scheduling-system

**AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System**

## Project Overview
This project is an AI-assisted scheduling system designed to automate faculty, classroom, and laboratory scheduling for the IT Department.

The system uses constraint-based scheduling techniques (Google OR-Tools CP-SAT) to generate optimized schedules while considering faculty availability, room limitations, laboratory requirements, teaching loads, and scheduling conflicts.

## Objectives
- Automate faculty scheduling processes
- Reduce manual scheduling errors
- Detect scheduling conflicts
- Optimize classroom and laboratory utilization
- Provide an AI-assisted schedule generation system

## Technology Stack

| Layer | Technology |
|---|---|
| Frontend | React, TypeScript, Tailwind CSS, Vite |
| Backend | Laravel 13, PHP 8.5, Laravel Sanctum |
| Database | PostgreSQL |
| AI Engine | Python, FastAPI, Google OR-Tools CP-SAT |
| Tools | Visual Studio Code, Git, GitHub |

## System Users

| Role | Responsibilities |
|---|---|
| **Administrator / Department Head** | The only login account. Manages users, faculty, rooms, and laboratories; generates, reviews, edits, approves, publishes, and prints schedules |
| **Faculty** | *(No system access)* — faculty are records (name, availability, qualifications, workload, subject assignments) used by the scheduling engine; schedules are distributed as printed/PDF copies |

## Main Features
- User Authentication (Sanctum + RBAC)
- Faculty Management
- Subject Management
- Room & Laboratory Management
- Faculty Availability Management
- Faculty–Subject Qualification Mapping
- AI Schedule Generation (OPTIMAL / PARTIAL / INFEASIBLE)
- Schedule Approval Workflow (Draft → Approved → Published → Archived)
- Session Editing with Conflict Detection
- Publish Conflict Gate (prevents cross-section double-booking)
- Unpublish Schedule (revert published → draft for editing)
- Print/Download published schedule for hard-copy distribution
- Schedule Reports

## Project Structure
```
backend/          Laravel API (port 8000)
frontend/         React Application (port 5173)
ai-engine/        Python Scheduling Engine (port 8001)
documentation/    System Documents & Architecture
database/         Database Files
diagrams/         System Diagrams
```

## Getting Started
1. **Backend:** `cd backend` → `composer install` → configure `.env` → `php artisan migrate --seed` → `php artisan serve`
2. **AI Engine:** `cd ai-engine` → `python -m venv .venv` → activate → `pip install -r requirements.txt` → `python -m uvicorn api.app:app --port 8001 --reload`
3. **Frontend:** `cd frontend` → `npm install` → `npm run dev`

> See `documentation/System-Architecture.md` for full architecture diagrams, and `documentation/Bug-Fix-Log.md` for the development history.

## Development Status
**Implementation and Testing — core features verified end-to-end:**
- ✅ All sections generate OPTIMAL schedules with zero unscheduled sessions
- ✅ Approval → Publish workflow working
- ✅ Zero cross-section conflicts (AI constraints + publish gate)
- ✅ Faculty portal showing published schedules correctly
- ✅ Conflict detection on manual session edits

## Team Members
| Name | Role |
|---|---|
| [Panuan, Casian James E.] | Lead Programmer |
| [Panuan/Lasola James kenneth] | Backend Developer |
| [Ugsang, Shiena] | Frontend Developer |
| [Bactol, Ejay] | Documentation |
| [Jumaoas, Grace ann] | QA Tester |

## License
This project is developed as a capstone project for academic purposes.
