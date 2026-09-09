# Software Requirements Specification (SRS)
## IT Faculty Scheduling System

**Version:** 1.0  
**Date:** August 22, 2026  
**Status:** Production Ready

---

## 1. Introduction

### 1.1 Purpose
This document specifies the software requirements for the AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System for the IT Department.

### 1.2 Scope
The system automates faculty scheduling processes, reduces manual errors, detects conflicts, and generates optimized schedules using constraint-based optimization techniques.

### 1.3 Definitions
- **CP-SAT**: Constraint Programming - SAT solver (Google OR-Tools)
- **RBAC**: Role-Based Access Control
- **OPTIMAL**: Schedule where all sessions are placed with no constraint violations
- **PARTIAL**: Schedule where some sessions are placed, others unscheduled with explanations
- **INFEASIBLE**: No valid schedule exists given current constraints

---

## 2. Overall Description

### 2.1 Product Perspective
Three-tier architecture:
- **Frontend**: React + TypeScript + Vite (port 5173)
- **Backend**: Laravel 13 + Sanctum (port 8000)
- **AI Engine**: Python FastAPI + OR-Tools CP-SAT (port 8001)
- **Database**: PostgreSQL

### 2.2 User Classes
| Role | Description |
|------|-------------|
| Administrator | Full system access, schedule generation, CRUD operations |
| Department Head | Schedule review, approval, publishing |
| Faculty | *(No system access — faculty are records used by the scheduler; schedules are distributed as printed/PDF copies)* |

### 2.3 Operating Environment
- Modern web browser (Chrome, Firefox, Edge)
- Node.js 18+ for frontend
- PHP 8.5+ for backend
- Python 3.10+ for AI engine
- PostgreSQL 14+

---

## 3. Functional Requirements

### 3.1 Authentication & Authorization
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-AUTH-001 | User login with email/password | High | ✅ Implemented |
| FR-AUTH-002 | Token-based session management | High | ✅ Implemented |
| FR-AUTH-003 | Admin-only access — login rejects non-admin roles; faculty are records, not users | High | ✅ Implemented |
| FR-AUTH-004 | Secure logout with token revocation | Medium | ✅ Implemented |

### 3.2 Faculty Management
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-FAC-001 | Create faculty records | High | ✅ Implemented |
| FR-FAC-002 | View faculty list with relationships | High | ✅ Implemented |
| FR-FAC-003 | Update faculty information | High | ✅ Implemented |
| FR-FAC-004 | Delete faculty with cascade cleanup | Medium | ✅ Implemented |
| FR-FAC-005 | Assign faculty to subjects (qualifications) | High | ✅ Implemented |
| FR-FAC-006 | Manage faculty availability (days/times) | High | ✅ Implemented |

### 3.3 Subject Management
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-SUB-001 | Create subject records | High | ✅ Implemented |
| FR-SUB-002 | View subject list with faculty | High | ✅ Implemented |
| FR-SUB-003 | Update subject information | High | ✅ Implemented |
| FR-SUB-004 | Delete subjects | Medium | ✅ Implemented |
| FR-SUB-005 | Define lecture/lab hours | High | ✅ Implemented |
| FR-SUB-006 | Specify required room types | High | ✅ Implemented |

### 3.4 Room Management
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-ROOM-001 | Create room records | High | ✅ Implemented |
| FR-ROOM-002 | View room list with status | High | ✅ Implemented |
| FR-ROOM-003 | Update room information | High | ✅ Implemented |
| FR-ROOM-004 | Delete rooms with cascade cleanup | Medium | ✅ Implemented |
| FR-ROOM-005 | Track room capacity | High | ✅ Implemented |
| FR-ROOM-006 | Manage room status (available/maintenance) | Medium | ✅ Implemented |

### 3.5 Section Management
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-SEC-001 | Create section records | High | ✅ Implemented |
| FR-SEC-002 | View section list with subjects | High | ✅ Implemented |
| FR-SEC-003 | Update section information | High | ✅ Implemented |
| FR-SEC-004 | Delete sections with cascade cleanup | Medium | ✅ Implemented |
| FR-SEC-005 | Assign subjects to sections | High | ✅ Implemented |
| FR-SEC-006 | Set preferred days and time windows | High | ✅ Implemented |

### 3.6 AI Schedule Generation
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-SCH-001 | Generate schedules for sections | High | ✅ Implemented |
| FR-SCH-002 | Enforce 8 hard constraints | High | ✅ Implemented |
| FR-SCH-003 | Return OPTIMAL/PARTIAL/INFEASIBLE status | High | ✅ Implemented |
| FR-SCH-004 | Provide explanations for unscheduled sessions | High | ✅ Implemented |
| FR-SCH-005 | Best-effort scheduling (partial solutions) | High | ✅ Implemented |
| FR-SCH-006 | Cross-section conflict prevention | High | ✅ Implemented |

### 3.7 Schedule Workflow
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-WF-001 | Draft schedule creation | High | ✅ Implemented |
| FR-WF-002 | Schedule approval by Department Head | High | ✅ Implemented |
| FR-WF-003 | Schedule publishing with conflict gate | High | ✅ Implemented |
| FR-WF-004 | Automatic archiving of old schedules | Medium | ✅ Implemented |
| FR-WF-005 | Schedule versioning | Medium | ✅ Implemented |

### 3.8 Schedule Distribution (replaces Faculty Portal)
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-DIST-001 | Print/Download published schedule as hard copy | High | 🔜 Planned — UI handled separately |
| FR-DIST-002 | Print-friendly weekly grid layout | High | 🔜 Planned — UI handled separately |

> **Design decision:** The Faculty Portal was removed. Faculty do not have login accounts; the system is used per semester by the department, and published schedules are distributed as printed/PDF copies.

### 3.9 Reports
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-RPT-001 | Faculty workload report | Medium | ✅ Implemented |
| FR-RPT-002 | Room utilization report | Medium | ✅ Implemented |
| FR-RPT-003 | Conflict history report | Low | ✅ Implemented |

---

## 4. Non-Functional Requirements

### 4.1 Security
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-SEC-001 | Secure authentication (Sanctum) | High | ✅ Implemented |
| NFR-SEC-002 | Role-based access control | High | ✅ Implemented |
| NFR-SEC-003 | Input validation and sanitization | High | ✅ Implemented |
| NFR-SEC-004 | SQL injection prevention (Eloquent ORM) | High | ✅ Implemented |
| NFR-SEC-005 | XSS prevention (React escaping) | High | ✅ Implemented |
| NFR-SEC-006 | CSRF protection | High | ✅ Implemented |

### 4.2 Performance
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-PERF-001 | Schedule generation < 30 seconds | High | ✅ Verified |
| NFR-PERF-002 | API response time < 2 seconds | Medium | ✅ Verified |
| NFR-PERF-003 | Database query optimization | Medium | ✅ Implemented |

### 4.3 Reliability
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-REL-001 | Graceful error handling | High | ✅ Implemented |
| NFR-REL-002 | Data integrity constraints | High | ✅ Implemented |
| NFR-REL-003 | Cascade delete operations | Medium | ✅ Implemented |
| NFR-REL-004 | Audit logging | Medium | ✅ Implemented |

### 4.4 Usability
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-USE-001 | Intuitive dashboard interface | High | ✅ Implemented |
| NFR-USE-002 | Responsive design | Medium | ✅ Implemented |
| NFR-USE-003 | Clear error messages | Medium | ✅ Implemented |
| NFR-USE-004 | Consistent UI patterns | Medium | ✅ Implemented |

### 4.5 Maintainability
| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| NFR-MAINT-001 | Clean code architecture | High | ✅ Implemented |
| NFR-MAINT-002 | MVC pattern (Laravel) | High | ✅ Implemented |
| NFR-MAINT-003 | Component-based UI (React) | High | ✅ Implemented |
| NFR-MAINT-004 | Documentation | Medium | ✅ Implemented |

---

## 5. System Constraints

### 5.1 Hard Constraints (Enforced by Solver)
1. Faculty qualification: Only qualified faculty assigned to subjects
2. Faculty availability: No scheduling on unavailable days (day-level; the section's preferred start/end window defines the scheduling hours)
3. Room type matching: Labs in labs, lectures in lecture rooms
4. Room capacity: Stude
