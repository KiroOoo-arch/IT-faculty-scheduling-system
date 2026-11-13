# Comparative Matrix

## IT Faculty Scheduling System

**Document:** Comparative Matrix
**Project title:** AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System
**Prepared for:** Academic presentation and project review
**Date prepared:** November 13, 2026
**Status:** Revised from repository evidence and publicly available product information

---

## 1. Purpose and scope

This document compares the IT Faculty Scheduling System with traditional/manual faculty scheduling and selected existing academic timetabling systems. It is intended for academic presentation, technical documentation, and project defense.

Claims about the proposed system are based on project repository documentation and implementation/test evidence. Claims about existing products are based only on publicly available official or institutional sources. Where a feature cannot be verified from public sources, it is marked **? (Not publicly verified)** rather than assumed absent.

### Verification status legend

| Status | Meaning |
|---|---|
| Implemented and verified | Functionality exists and has supporting test or verification evidence |
| Implemented, verification limited | Functionality exists, but some aspects are not fully tested or verified |
| Partially implemented | Some related functionality exists, but the full capability is not complete |
| Planned / not implemented | Proposed or documented for future implementation, but not currently available |

### Proposed-system capability summary

| Capability | Status |
|---|---|
| Laravel backend and React frontend | Implemented and verified |
| FastAPI AI Engine integration | Implemented and verified |
| CP-SAT scheduling engine (Google OR-Tools) | Implemented and verified |
| Faculty, room, section, and subject management | Implemented and verified |
| Constraint-based schedule generation | Implemented and verified |
| Cross-section conflict handling | Implemented and verified |
| Schedule approval, publish, unpublish, and rejection workflow | Implemented and verified |
| Reports and generation logs | Implemented and verified |
| Bearer-token authentication for administrators | Implemented and verified |
| Multi-factor authentication | Implemented and verified |
| Backend-to-AI-Engine bearer authentication | Implemented and verified |
| Database safety guards and protected denylist | Implemented and verified |
| Loopback-only AI Engine launcher | Implemented and verified |
| Sanitized API errors | Implemented and verified |
| Request-size limits | Implemented and verified |
| API rate limiting | Implemented and verified |
| Production API documentation restrictions | Implemented and verified |
| Browser security headers with CSP in Report-Only mode | Implemented and verified |
| Same-origin development API configuration (`/api`, `/sanctum`) | Implemented and verified |
| Frontend CSRF/cookie-request preparation helper | Implemented and verified (preparation only) |
| Sanctum cookie-authentication migration | Planned / not implemented |
| MFA-gated session establishment | Planned / not implemented |
| Production deployment security and hosting | Planned / not implemented |
| Signature-before-approval workflow | Planned / not implemented |
| Multi-role authorization beyond administrator | Planned / not implemented |
| HSTS and enforced (non-Report-Only) CSP | Planned / not implemented |

---

## 2. Comparative matrix

| Features | Traditional/Manual Scheduling | UniTime | aSc TimeTables | Scientia Enterprise Timetabler | Proposed IT Faculty Scheduling System |
|---|:---:|:---:|:---:|:---:|:---:|
| Web-Based System | ✗ | ✓ | ✓ | ✓ | ✓ |
| Faculty Scheduling | ✓ | ✓ | ✓ | ✓ | ✓ |
| Classroom Scheduling | ✓ | ✓ | ✓ | ✓ | ✓ |
| Laboratory Scheduling | ✓ | ✓ | ✓ | ? | ✓ |
| Conflict Detection | △ | ✓ | ✓ | ✓ | ✓ |
| Constraint-Based Scheduling | △ | ✓ | ✓ | ✓ | ✓ |
| Automated Schedule Generation | ✗ | ✓ | ✓ | ✓ | ✓ |
| AI-Assisted Schedule Generation | ✗ | △ | △ | ? | △ |
| Student-Centered Scheduling | △ | ✓ | ? | ? | △ |
| Faculty Availability Management | △ | ✓ | ✓ | ? | ✓ |
| Faculty Workload Management | △ | ✓ | ? | ? | ✓ |
| Faculty-Subject Qualification Checking | △ | ✓ | ? | ? | ✓ |
| Lecture and Laboratory Assignment | ✓ | ✓ | ✓ | ✓ | ✓ |
| Room Capacity and Type Validation | △ | ✓ | ✓ | ? | ✓ |
| Cross-Section Conflict Detection | △ | ✓ | ✓ | ✓ | ✓ |
| Preferred Class Time Frame | △ | ✓ | ✓ | ? | ✓ |
| Manual Schedule Adjustment | ✓ | ✓ | ✓ | ✓ | ✓ |
| Schedule Approval Workflow | △ | ? | ? | ? | ✓ |
| Schedule Publication and Unpublication | ✗ | ? | ? | ? | ✓ |
| Department-Level Administrative Management | ✓ | ✓ | ✓ | ✓ | ✓ |
| Reports and Generation Logs | △ | ✓ | ✓ | ✓ | ✓ |
| Multi-Factor Authentication | ✗ | ? | ? | ? | ✓ |
| Integration with Other Systems | △ | ✓ | ✓ | ✓ | △ |
| Customized Scheduling for the IT Department | △ | △ | △ | △ | ✓ |

### Legend

| Symbol | Meaning |
|---|---|
| ✓ | Feature available and supported by evidence |
| △ | Feature partially available or available with limitations |
| ✗ | Feature confirmed unavailable or not supported |
| ? | Not publicly verified from the reviewed sources |
| — | Not applicable to the comparison category |

---

## 3. Evidence and remarks

- **Traditional/manual scheduling** is represented as a general practice rather than one product. Its symbols reflect typical spreadsheet, paper, or office-tool workflows; qualified wording is used because institutional practices vary.
- **UniTime** is a broader university timetabling platform with documented course timetabling, instructor assignment, room allocation, constraint handling, conflict handling, solution reports, and SIS integration. MFA and approval/publication workflow are not established from the reviewed public sources.
- **aSc TimeTables** is an established timetabling product with documented automatic generation, constraints, room scheduling, manual editing, reports, and sharing. Some workload, qualification, authentication, and student-scheduling claims are not publicly verified.
- **Scientia Enterprise Timetabler** claims rely partly on institutional documentation. Constraint-aware scheduling, room allocation, clash checking, and manual editing are described in reviewed institutional material; vendor-specific feature claims remain qualified or unverified.
- **AI-assisted schedule generation** does not mean a trained machine-learning model. The proposed system uses Google OR-Tools CP-SAT, a constraint-programming optimization solver. CP-SAT is a constraint-programming technology and should be described as optimization rather than as proof of artificial intelligence. Competitors may also use constraint solvers, but public AI-specific claims are not uniformly verified.
- **Student-centered scheduling** in the proposed system means section-level scheduling with student counts and capacity checks. It does not include individual student sectioning or personalized timetable optimization.
- **Faculty availability and qualifications** are enforced through CP-SAT constraints, including availability windows and `faculty_subjects` qualification data.
- **Room capacity and type validation** is enforced through capacity and room-type constraints.
- **Cross-section conflict detection** is enforced against other sections' draft, approved, and published sessions in the same academic year and semester.
- **Preferred class time frame** is enforced through each section's preferred days and time window.
- **Schedule approval, publication, and unpublication** reflect the implemented draft, approve, publish, unpublish, reject, and delete workflow.
- **Reports and generation logs** reflect implemented faculty workload, room utilization, section summary, schedule status, and generation-log reporting.
- **Multi-factor authentication** is implemented for administrator accounts, but MFA-gated cookie session establishment remains planned.
- **Integration with other systems** is marked as partial for the proposed system because the implemented integration is Laravel-to-FastAPI communication over a shared bearer token, not broad institutional system integration.
- **Customized scheduling for the IT Department** reflects the project's department-specific subject, section, room, faculty, and workflow scope.

---

## 4. Comparative analysis

1. **Manual scheduling limitations addressed:** The proposed system addresses manual effort, inconsistent workload balancing, delayed conflict detection, and informal constraint handling through a validated data model and automated CP-SAT generation.
2. **Distinguishing capabilities:** The proposed system combines faculty, subject, room, and section management with constraint-based generation, cross-section conflict checks, manual edit validation, approval/publication workflow, reports, generation logs, and documented security controls.
3. **Broader capabilities of established systems:** UniTime, aSc TimeTables, and Scientia Enterprise Timetabler may provide broader enterprise features such as student sectioning, exam timetabling, multi-user distributed timetabling, institutional integrations, and larger-scale optimization. The proposed system does not currently claim those capabilities.
4. **Contribution within defined scope:** The project contributes a focused, verifiable faculty scheduling workflow with explicit constraints, transparent partial/infeasible reporting, and documented testing and security work.

---

## 5. Project justification

The proposed system is useful within its intended IT department scheduling scope because it converts manual scheduling rules into explicit, testable constraints. It enforces faculty qualifications, availability, workload limits, room type and capacity, section windows, self-overlap, midday break, and cross-section conflicts. It also provides administrative review, approval, publication, reporting, and generation-log transparency. Its scope is deliberately narrower than established enterprise timetabling systems, but its constraints and workflow are documented and verifiable.

---

## 6. Limitations and future improvements

- Sanctum cookie authentication, MFA-gated sessions, production deployment security, signature-before-approval, broader role support, login rate limiting, HSTS, enforced CSP, and migration of remaining direct fetch calls are not yet complete.
- CP-SAT optimizes scheduling but does not guarantee that every scheduling problem is solvable.
- Real browser behavior, production HTTPS, and deployment security remain unverified.
- The proposed system does not provide individual student sectioning, exam timetabling, or broad institutional system integration.

---

## 7. References

| # | Product/System | Source title | Publisher/Organization | URL | Access date |
|---|---|---|---|---|---|
| 1 | UniTime | UniTime University Timetabling | UniTime / Apereo Foundation | https://www.unitime.org/ | November 13, 2026 |
| 2 | UniTime | UniTime Documentation | UniTime | https://help.unitime.org/ | November 13, 2026 |
| 3 | UniTime | Instructor Scheduling | UniTime | https://help.unitime.org/instructor-scheduling | November 13, 2026 |
| 4 | UniTime | Solution Reports | UniTime | https://help34.unitime.org/solution-reports | November 13, 2026 |
| 5 | UniTime | Class Assignment | UniTime | https://help34.unitime.org/class-assignment | November 13, 2026 |
| 6 | UniTime | Course Timetabling | UniTime | https://help.unitime.org/course-timetabling | November 13, 2026 |
| 7 | aSc TimeTables | aSc TimeTables | aSc (Edupage) | https://www.asctimetables.com/ | November 13, 2026 |
| 8 | aSc TimeTables | aSc Help | aSc (Edupage) | https://www.help.asctimetables.com/ | November 13, 2026 |
| 9 | Scientia Enterprise Timetabler | Timetabling & Scheduling | TechnologyOne (Scientia) | https://www.scientia.com/ | November 13, 2026 |
| 10 | Scientia Enterprise Timetabler | Enterprise Timetabler training documentation | University of Edinburgh (institutional documentation) | https://edwebcontent.ed.ac.uk/sites/default/files/atoms/files/timetabling_enterprise_training_manual_2017.pdf | November 13, 2026 |
| 11 | Scientia Enterprise Timetabler | QMUL Enterprise Timetabler guides | Queen Mary University of London (institutional documentation) | https://www.qmul.ac.uk/registry-services/media/timetablingsupport/docs/QMUL-Enterprise-Desktop-Reference-Data-Manager---User-Guide.pdf | November 13, 2026 |
| 12 | Project | Implementation Status & Technical Documentation | Project repository | `documentation/Implementation_Status.md` | November 13, 2026 |
| 13 | Project | Scheduling Constraints | Project repository | `documentation/Constraints.md` | November 13, 2026 |
| 14 | Project | Project Remaining Tasks and Roadmap | Project repository | `documentation/PROJECT-REMAINING-TASKS-AND-ROADMAP.md` | November 13, 2026 |

---

## 8. Notes on evidence limitations

- No competitor feature is inferred solely from the absence of a public mention.
- Several Scientia Enterprise Timetabler claims rely on institutional documentation rather than vendor feature pages; those entries are marked accordingly.
- The proposed system is not described as deployed, publicly accessible, or production-ready. Deployment and production security work remain pending.
- CP-SAT, automated timetabling, and optimization are established technologies; this document describes their application in this project without claiming novelty for the underlying technologies.
- The proposed system's AI-assisted label is used cautiously; the verified implementation is CP-SAT constraint optimization rather than a learned AI model.