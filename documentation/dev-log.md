
## ER Diagram

```mermaid
erDiagram
    USERS ||--o{ FACULTY : has
    USERS ||--o{ SCHEDULES : approves
    FACULTY ||--o{ FACULTY_SUBJECTS : qualified
    FACULTY ||--o{ FACULTY_AVAILABILITIES : available
    FACULTY ||--o{ SCHEDULE_SESSIONS : teaches
    SUBJECTS ||--o{ FACULTY_SUBJECTS : taught-by
    SUBJECTS ||--o{ SECTION_SUBJECTS : assigned-to
    SUBJECTS ||--o{ SCHEDULE_SESSIONS : scheduled-as
    SECTIONS ||--o{ SECTION_SUBJECTS : includes
    SECTIONS ||--o{ SCHEDULES : generates
    ROOMS ||--o{ SCHEDULE_SESSIONS : hosts
    SCHEDULES ||--o{ SCHEDULE_SESSIONS : contains

    USERS {
        int id PK
        string name
        string email
        string password
        string role "admin | faculty"
    }

    FACULTY {
        int id PK
        int user_id FK
        string employee_no
        string faculty_type "Full_time | Part_time"
        int max_teaching_load
        boolean is_active
    }

    SUBJECTS {
        int id PK
        string code
        string title
        string lab_room_type
        int hours_per_week
    }

    SECTIONS {
        int id PK
        string name
        int year_level
        string semester_name
        string preferred_days
        time preferred_start_time
        time preferred_end_time
    }

    ROOMS {
        int id PK
        string name
        string type "lecture | laboratory"
        int capacity
    }

    SCHEDULES {
        int id PK
        int section_id FK
        string status "draft | approved | published | archived"
        int approved_by FK
        timestamp approved_at
        timestamp created_at
    }

    SCHEDULE_SESSIONS {
        int id PK
        int schedule_id FK
        int subject_id FK
        int faculty_id FK
        int room_id FK
        string session_type
        int day_of_week
        time start_time
        time end_time
    }

    FACULTY_AVAILABILITIES {
        int id PK
        int faculty_id FK
        int day_of_week
        time start_time
        time end_time
    }

