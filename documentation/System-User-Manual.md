# Faculty Scheduling System - User Manual

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Getting Started](#2-getting-started)
3. [Admin Dashboard](#3-admin-dashboard)
4. [Managing Users](#4-managing-users)
5. [Managing Faculty](#5-managing-faculty)
6. [Managing Subjects](#6-managing-subjects)
7. [Managing Rooms](#7-managing-rooms)
8. [Managing Sections](#8-managing-sections)
9. [Generating Schedules](#9-generating-schedules)
10. [Reviewing and Approving Schedules](#10-reviewing-and-approving-schedules)
11. [Editing Sessions](#11-editing-sessions)
12. [Faculty Portal](#12-faculty-portal)
13. [Reports](#13-reports)
14. [Schedule Lifecycle](#14-schedule-lifecycle)
15. [Troubleshooting and FAQ](#15-troubleshooting-and-faq)

---

## 1. System Overview

The **Faculty Scheduling System** is a web application that uses AI-powered scheduling to automatically generate class timetables. It assigns faculty, rooms, and time slots while respecting constraints like availability, room capacity, and workload limits.

**Key Features:**
- AI Schedule Generation with constraint solver
- Approval Workflow (Draft > Approved > Published)
- Faculty Management with qualifications and availability
- Room Management with capacity tracking
- Reports and Analytics (workload, utilization, status)
- Faculty Portal for viewing published schedules

**User Roles:**
- **Admin** - Full access to all features
- **Faculty** - Can only view their own published schedule

---

## 2. Getting Started

### Logging In

1. Open the application in your web browser
2. Enter your email and password
3. Click Log in
4. Admin users go to the Admin Dashboard; Faculty users go to the Faculty Dashboard

### Logging Out

Click the Log out button in the top-right corner.

---

## 3. Admin Dashboard

The Admin Dashboard is your home screen after logging in.

**Navigation Buttons:**
- Users - Manage user accounts
- Faculty - Manage faculty, qualifications, availability
- Subjects - Add and manage course subjects
- Rooms - Add and manage classrooms and labs
- Sections - Create class sections and assign subjects
- Reports - View analytics and summaries

**Generate Schedule:**
1. Select a section from the dropdown
2. Click Generate Schedule
3. The AI processes and creates sessions
4. A result message shows how many sessions were created

**Schedule Review:**
All generated schedules appear as cards with status badges and session tables.

---

## 4. Managing Users

**Path:** Dashboard > Users

### Creating a User
1. Fill in Name, Email, Password
2. Select Role (Admin or Faculty)
3. Click Create

### Editing
1. Click Edit next to the user
2. Modify fields (leave password blank to keep current)
3. Click Update

### Deleting
1. Click Delete, then confirm

> Note: When you create a Faculty member from the Faculty page, a user account is automatically created.

---

## 5. Managing Faculty

**Path:** Dashboard > Faculty

### Creating a Faculty Member
1. Fill in: Full Name, Email, Password, Faculty Type, Max Teaching Load
2. Click Create Faculty

This creates both the user account and faculty record in one step.

### Editing
Click Edit to open the form with 3 sections:

a) **Basic Info** - Faculty type and max load

b) **Subject Qualifications** - Check subjects this faculty can teach

c) **Availability** - Set start/end times and select available days

Click Save when done.

> If no availability days are set, the AI schedules them on all section days.

---

## 6. Managing Subjects

**Path:** Dashboard > Subjects

### Creating a Subject
1. Fill in: Code, Title, Year Level, Semester, Lecture Hours, Lab Hours, Lab Room Type
2. Click Create

### Editing and Deleting
Click Edit or Delete next to any subject.

> Subjects must be assigned to sections AND have qualified faculty before appearing in schedules.

---

## 7. Managing Rooms

**Path:** Dashboard > Rooms

### Creating a Room
1. Fill in: Name, Type (Lecture or Computer Lab), Capacity, Status
2. Click Add Room

> The AI matches lab subjects to lab rooms and lecture subjects to lecture rooms automatically.

---

## 8. Managing Sections

**Path:** Dashboard > Sections

### Creating a Section
1. Fill in: Name, Year Level, Academic Year, Semester, Start/End Time
2. Select Preferred Days (Mon through Sun)
3. Check the boxes for subjects this section will take
4. Click Create

> A section must have subjects assigned before generating a schedule.

---

## 9. Generating Schedules

### Prerequisites
- At least one section with subjects assigned
- At least one faculty member qualified to teach those subjects
- At least one room of the correct type

### Steps
1. Go to the Admin Dashboard
2. Select the section from the dropdown
3. Click Generate Schedule
4. The AI assigns faculty, rooms, and time slots
5. Result: Success (OPTIMAL), Partial, or Error

### Old Schedules
Existing drafts are automatically archived. Click Show Archived to view them.

---

## 10. Reviewing and Approving

### Draft (Yellow Badge)
- Sessions can be edited
- Click Approve to proceed

### Approved (Blue Badge)
- Sessions can still be edited
- Click Publish to make visible to faculty

### Published (Green Badge)
- Live and visible to faculty
- Cannot be edited while published
- Click Unpublish to revert to Draft

### Deleting
Draft and Archived schedules can be deleted. Published must be unpublished first.

---

## 11. Editing Sessions

1. Click Edit on any session in a Draft or Approved schedule
2. Change Day, Room, Start Time, End Time, or Faculty
3. Click Save Changes

The system checks for conflicts and shows an error if one is found.

---

## 12. Schedule Distribution (Print / Download)

After a schedule is **Published**, the Admin can produce a hard copy for distribution:

1. Open the published schedule
2. Click **Print / Download**
3. A clean, print-friendly weekly grid opens (department header, section name, day columns × time rows)
4. The browser print dialog opens — choose a printer, or choose **Save as PDF**
5. The printed/PDF copies are distributed to faculty and posted for students

> **Note:** Faculty do not log into the system. Faculty are records (name, availability, qualifications, workload, employment type, subject assignments) used by the scheduling engine. Schedules reach faculty as printed/PDF copies.

---

## 13. Reports

**Path:** Dashboard > Reports

**Overview Tab:**
- Schedule Status Cards (Draft/Approved/Published counts)
- Quick Stats (faculty, rooms, sections)

**Faculty Load Tab:**
- Assigned hours vs. max load per faculty
- Utilization bar: green < 70%, yellow 70-90%, red > 90%

**Room Usage Tab:**
- Booked hours per week per room, sorted by usage

**Sections Tab:**
- Total sessions, hours, faculty count, subjects per section

**Generation Logs Tab:**
- History of all AI generation attempts

---

## 14. Schedule Lifecycle

**SETUP PHASE (once per semester):**
1. Create Rooms
2. Create Subjects
3. Create Faculty (with qualifications and availability)
4. Create Sections (with assigned subjects)

**GENERATION PHASE:**
5. Select a section on the Dashboard
6. Click Generate Schedule
7. Review the generated sessions

**APPROVAL PHASE:**
8. Edit sessions if needed
9. Click Approve
10. Final review
11. Click Publish

**PUBLISHED:**
12. Faculty can now see their schedules

**Status Summary:**
- Draft (Yellow) - Editable, not visible to faculty
- Approved (Blue) - Editable, not visible to faculty
- Published (Green) - Not editable, visible to faculty
- Archived (Gray) - Not editable, not visible

---

## 15. Troubleshooting

**Q: Subjects missing from generated schedule?**
A: Check that the subject is assigned to the section, faculty is qualified, and a room exists.

**Q: AI says no faculty available?**
A: Check qualifications, availability days, and that faculty is active.

**Q: Cannot delete a published schedule?**
A: Unpublish it first, then delete.

**Q: Room conflict when editing?**
A: The room is booked. Choose a different time or room.

**Q: Faculty cannot see any schedule?**
A: Schedule must be Published. Draft and Approved are admin-only.

**Q: How to change faculty available days?**
A: Faculty > Edit > Availability section > toggle day buttons > Save.

**Q: Can I regenerate a schedule?**
A: Yes. Old drafts are auto-archived. Click Show Archived to view them.

---

*System User Manual - Faculty Scheduling System*
*Version 1.0*
