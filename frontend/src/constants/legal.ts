/**
 * The Privacy Policy and the Terms of Use.
 *
 * One module holds the wording of both documents so that there is exactly one
 * copy of each sentence, and so the text sits next to the code it describes
 * rather than in a database row or a Word file that drifts out of date.
 *
 * What is NOT here: the version, the effective date, and the privacy contact.
 * Those come from the server (`GET /api/legal`, backed by `backend/config/legal.php`)
 * because acceptance is validated against the server's version — a client that
 * decided its own version could record agreement to a revision that is not in
 * force.
 *
 * The content was written from the implementation, not from a template: every
 * statement about what is collected, who can see it, and what protects it was
 * checked against the code. Where an answer depends on a college decision that
 * has not been made (retention periods, hosting, a Data Protection Officer,
 * liability terms), the text says so through a `pending` block instead of
 * inventing a fact. Reviewers are looking for those.
 *
 * Editing the wording: change the blocks below. Nothing else needs to change —
 * but a material change must also bump the version in `backend/config/legal.php`
 * so that every account is asked to accept the revision.
 */

export type LegalBlock =
  | { kind: 'paragraph'; text: string }
  | { kind: 'list'; items: string[] }
  /** A statement the college must confirm. Rendered visibly as an open item. */
  | { kind: 'pending'; text: string }

export type LegalSection = {
  id: string
  heading: string
  blocks: LegalBlock[]
}

export type LegalDocumentContent = {
  id: 'privacy' | 'terms'
  /** Route path, used by the footer, the acceptance prompt and the documents. */
  path: string
  title: string
  /** One-line description for links and the acceptance prompt. */
  summary: string
  /** Shown at the top of the document and on the acceptance prompt. */
  standing: string
  sections: LegalSection[]
  /** Wording-level items the college must confirm; the API adds the rest. */
  reviewNotes: string[]
}

export const PRIVACY_POLICY: LegalDocumentContent = {
  id: 'privacy',
  path: '/privacy',
  title: 'Privacy Policy',
  summary: 'What personal information this scheduling system processes, why, who can see it, and how it is protected.',
  standing:
    'This policy describes how personal information is handled in the Faculty, Classroom and Laboratory Scheduling System of Lapu-Lapu City College. It is written for the administrators who use the system and for the faculty whose scheduling records are kept in it.',
  reviewNotes: [
    'Lawful basis: the policy lists the processing purposes but does not assert which criterion under the Data Privacy Act of 2012 each purpose relies on. The college must identify and record that basis.',
    'Retention and disposal: no retention period or disposal schedule has been agreed.',
    'Hosting and backups: the system currently runs on a single college-controlled machine. If it is moved to a server, a hosted database, or an off-site backup, this section must be updated before that happens.',
    'Counsel review: the wording has not been reviewed by the college’s authorized personnel or by qualified Philippine legal counsel.',
  ],
  sections: [
    {
      id: 'purpose-and-scope',
      heading: '1. Purpose and scope of this policy',
      blocks: [
        {
          kind: 'paragraph',
          text: 'This policy concerns personal information processed through the Faculty, Classroom and Laboratory Scheduling System operated by Lapu-Lapu City College ("the college"). It explains what information the system holds, why it is held, who can see it, how long it is kept, and how it is protected.',
        },
        {
          kind: 'paragraph',
          text: 'It covers two groups of people: the college personnel who sign in to the system to manage scheduling, and the faculty members whose teaching records are entered into it. Students are not users of the system and are not the subject of any record held in it; sections and their schedules describe classes, not individual students.',
        },
        {
          kind: 'paragraph',
          text: 'The reference point for this policy is the Philippine Data Privacy Act of 2012 (Republic Act No. 10173) and its Implementing Rules and Regulations. Nothing in this page is a legal opinion, and publishing it does not by itself make the college compliant with the Act.',
        },
      ],
    },
    {
      id: 'information-collected',
      heading: '2. What information the system processes',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The categories below are the information this application actually reads and writes. Anything not listed here is not collected by the system.',
        },
        {
          kind: 'list',
          items: [
            'Account information (for personnel who sign in): name, email address, role, and a password stored only as a bcrypt hash. Only administrator accounts exist — faculty members do not have accounts and cannot sign in by design.',
            'Session credentials: when an account signs in, the system issues an API access token, which is stored in the database only as a hash, together with the last time it was used. Signing in again ends that account’s previous tokens.',
            'Faculty records (employees and teaching staff, kept as records rather than as users): name, employment type (full-time or part-time), maximum teaching load, the subjects the faculty member is qualified to teach, and the day-of-week availability windows declared for scheduling.',
            'Scheduling data: sections (name, year level, semester, student count and preferred day/time window), the subjects assigned to each section, and the sessions in a generated timetable — subject, assigned faculty member, assigned room, day, start and end time, session type, and schedule status.',
            'Review and approval records: which account approved a schedule and when a schedule was generated.',
            'Schedule generation records: for each attempt to generate a timetable, the section concerned, the account that requested it, the outcome, a plain-language message, and the reasons any session could not be placed.',
            'Terms acceptance records: the account, the version of the Terms of Use that was accepted, and the date and time of acceptance.',
            'Technical and diagnostic data: the application writes operational errors and diagnostics to a log file on the machine that runs it.',
            'Browser storage: the access token and a copy of the account profile (name, email, role) are kept in the browser’s local storage so the application can keep the session open, and are removed on sign-out.',
          ],
        },
        {
          kind: 'paragraph',
          text: 'The system does not use analytics or advertising tools, does not set third-party cookies, does not load scripts, fonts or images from external services, does not collect location data or biometric data, and does not send email or text messages.',
        },
      ],
    },
    {
      id: 'purpose-of-processing',
      heading: '3. Why the information is processed',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The information is processed to carry out the college’s scheduling work and to keep the application secure. Specifically:',
        },
        {
          kind: 'list',
          items: [
            'Managing accounts, signing users in and out, and controlling who may reach which function.',
            'Keeping the college’s master data: faculty, subjects, rooms and laboratories, and sections.',
            'Generating a draft timetable for a section with the constraint-based scheduling engine.',
            'Preventing conflicts — the same faculty member or the same room being committed twice in one academic term — before a timetable is written.',
            'Reviewing, approving, publishing, printing and distributing timetables and running scheduling reports.',
            'Keeping a record of what was generated, by whom, and why a session could not be placed, so that the outcome can be explained and corrected.',
            'Recording acceptance of the Terms of Use, so the college can show which version each account agreed to.',
            'Diagnosing faults and protecting the system from misuse.',
          ],
        },
        {
          kind: 'pending',
          text: 'Lawful basis: the college must confirm and record the criterion under the Data Privacy Act of 2012 (for example a legal obligation, an institutional function, or consent) that applies to each purpose above. This implementation does not assert one.',
        },
      ],
    },
    {
      id: 'access-and-disclosure',
      heading: '4. Who can see it, and where it goes',
      blocks: [
        {
          kind: 'paragraph',
          text: 'Within the college, access is limited to the administrator accounts the college creates. The server checks the account’s role on every administrative request and refuses requests from any other account, so holding a valid session is not enough to reach the scheduling functions.',
        },
        {
          kind: 'paragraph',
          text: 'Nothing in the system is published to the open internet. The application has no public data endpoint, and the pages that exist without signing in are only these legal documents.',
        },
        {
          kind: 'paragraph',
          text: 'About the AI scheduling engine: it is a separate service that runs on the same college-controlled machine as the application, listening on the local loopback address (127.0.0.1). It reads the section, faculty, room and existing-session data it needs directly from the same college database over that local connection, returns a proposed timetable, and never writes to the database. It holds no personal data of its own and makes no outbound calls to the internet. No external or cloud-based AI provider, and no third-party analytics or tracking service, receives any information from this system.',
        },
        {
          kind: 'paragraph',
          text: 'The system sends no email or messages, and no third-party processor is configured to receive personal information.',
        },
        {
          kind: 'pending',
          text: 'Hosting and third parties: the application currently runs on a single college-controlled machine using its own PostgreSQL database. If any part of it is later hosted externally, backed up off-site, or connected to another service, the college must identify that provider and update this policy before personal information is transferred there.',
        },
      ],
    },
    {
      id: 'storage-retention-deletion',
      heading: '5. Storage, retention and deletion',
      blocks: [
        {
          kind: 'paragraph',
          text: 'Where the information is kept: in the college’s own PostgreSQL database and log file on the machine that runs the application. The scheduling engine holds no separate copy.',
        },
        {
          kind: 'list',
          items: [
            'Faculty, subject, room and section records remain in the database until they are changed or deleted through the application.',
            'A published timetable cannot be deleted until it is unpublished, and faculty, subjects, rooms or sections that a published timetable depends on cannot be deleted without an explicit confirmation, so that a live timetable cannot be broken silently.',
            'Generated timetables and their sessions remain until they are deleted or replaced, and the reason a session could not be placed is kept with the generation record.',
            'Schedule generation records are kept until an administrator clears them from the Reports page; they are not removed automatically, and clearing them removes the record of who requested each run.',
            'Access tokens remain until the account signs in again, signs out, or is deleted.',
            'An account can be deleted by an administrator. The account’s Terms acceptance record is kept as an accountability record, with the link to the deleted account cleared.',
          ],
        },
        {
          kind: 'pending',
          text: 'Retention periods: no retention or disposal schedule has been agreed for scheduling records, generation records, or Terms acceptance records. The college must set one, and state whether any record must be kept for a fixed period for institutional or legal reasons.',
        },
        {
          kind: 'pending',
          text: 'Account deletion is a manual administrative action. There is no automatic deletion of records when a faculty member leaves the college; that must be handled by the college’s own process.',
        },
      ],
    },
    {
      id: 'security-safeguards',
      heading: '6. How the information is protected',
      blocks: [
        {
          kind: 'paragraph',
          text: 'These protections are in place in the application as implemented:',
        },
        {
          kind: 'list',
          items: [
            'Passwords are stored only as bcrypt hashes, never as readable text.',
            'Access tokens are stored only as hashes, so the database does not hold a usable token.',
            'Signing in ends that account’s previous tokens, so a forgotten session is not left usable.',
            'Administrative endpoints require an authenticated account with the administrator role, and answer 401 or 403 otherwise; the check is made on the server, not only in the browser.',
            'Deleting master data that a published timetable depends on is refused until the dependency is acknowledged.',
            'Database, mail and application credentials are held in server-side configuration, not in the code or in the browser.',
          ],
        },
        {
          kind: 'paragraph',
          text: 'Limits the college should know about before relying on this page:',
        },
        {
          kind: 'list',
          items: [
            'In the current development configuration the application is served over plain HTTP on the local machine, so traffic is not encrypted in transit. TLS must be in place before the system is reachable beyond a controlled local network.',
            'Encryption of the database at rest has not been configured.',
            'There is no multi-factor authentication.',
            'There is no per-action audit trail. The only activity record is the schedule generation record, which names the account that requested each generation.',
            'The access token is kept in the browser’s local storage, so anyone who can read the browser profile of a signed-in machine can use that session — the machines used to sign in must be protected.',
            'There is no automated monitoring or alerting for intrusion attempts.',
          ],
        },
        {
          kind: 'pending',
          text: 'The college must review these gaps, and the arrangements for hosting, backups, access to the database and device security, before treating the system as production-ready.',
        },
      ],
    },
    {
      id: 'rights-and-inquiries',
      heading: '7. Your rights and privacy inquiries',
      blocks: [
        {
          kind: 'paragraph',
          text: 'Under the Data Privacy Act of 2012, and subject to the conditions and exceptions the law sets, a person whose personal information is processed has the right to be informed that it is being processed, to object to processing, to access the information held about them, to have inaccurate information corrected, to have information erased or blocked in the cases the law allows, to claim damages for violations of their rights, and to have their data moved to another service provider where the law provides for it. A complaint may also be filed with the National Privacy Commission.',
        },
        {
          kind: 'paragraph',
          text: 'To make a request about a faculty record or an account in this system, contact the college’s designated privacy contact. Requests may be limited where the law, or the college’s own record-keeping obligations, require a particular record to be kept.',
        },
        {
          kind: 'pending',
          text: 'Privacy contact: the college has not yet designated a Data Protection Officer or published a privacy contact for this system, so this page cannot yet tell users where to send a request. Until that is confirmed, requests about information in this system should be raised with the college administration office directly.',
        },
      ],
    },
    {
      id: 'effective-date-and-revisions',
      heading: '8. Effective date and revisions',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The version and effective date shown at the top of this page are taken from the server, which is the authority on which revision is current. This document is revised by changing its version and effective date together; a material revision that changes what is collected, why, or who can see it should also cause every account to be asked to accept the revised Terms of Use.',
        },
        {
          kind: 'pending',
          text: 'Neither this policy nor the Terms of Use has an effective date yet, because the college has not adopted them. Until it does, both pages are drafts marked for review and must not be presented as approved institutional policy.',
        },
      ],
    },
    {
      id: 'legal-context',
      heading: '9. Legal context and limits of this document',
      blocks: [
        {
          kind: 'paragraph',
          text: 'This policy is written with reference to the Data Privacy Act of 2012 (Republic Act No. 10173) and its Implementing Rules and Regulations. It is a description of how this application handles personal information, prepared to support the college’s transparency obligations and its own privacy governance.',
        },
        {
          kind: 'paragraph',
          text: 'It is not a certification of compliance, it is not legal advice, and it does not by itself establish that the college meets its obligations under the Act. Compliance depends on the college’s policies, its designations and records, its agreements with any service providers, and how this system is operated — not on the existence of this page.',
        },
        {
          kind: 'pending',
          text: 'The college’s authorized personnel, and qualified Philippine legal counsel where appropriate, should review this text before it is adopted or relied upon.',
        },
      ],
    },
  ],
}

export const TERMS_OF_USE: LegalDocumentContent = {
  id: 'terms',
  path: '/terms',
  title: 'Terms of Use',
  summary: 'The conditions for using the scheduling system: authorized use, account security, responsibilities for the data entered, and the limits of AI-generated schedules.',
  standing:
    'These terms apply to everyone who signs in to the Faculty, Classroom and Laboratory Scheduling System of Lapu-Lapu City College. By signing in and using the system you agree to them.',
  reviewNotes: [
    'Liability and disputes: the college must decide, with legal advice, whether any limitation of liability, dispute-resolution clause or similar term is to be included. None has been written here, and no blanket waiver is implied.',
    'Suspension and enforcement: the process for suspending an account, and who decides, must be confirmed by the college.',
    'Ownership: ownership of the application, of the college’s institutional data, and the permitted redistribution of printed timetables must be confirmed by the college.',
    'Maintenance windows: no service window or support commitment has been agreed.',
  ],
  sections: [
    {
      id: 'purpose-and-authorized-use',
      heading: '1. Purpose and authorized use',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The system exists to build, review, approve, publish and distribute the college’s faculty, classroom and laboratory timetables. It may be used only for that institutional purpose, by the people the college authorizes to do that work.',
        },
        {
          kind: 'paragraph',
          text: 'The system is an internal scheduling tool, not a public service. Its content, including timetables, faculty records and reports, is college information and is used for official college purposes only.',
        },
      ],
    },
    {
      id: 'who-may-use-the-system',
      heading: '2. Who may use the system',
      blocks: [
        {
          kind: 'list',
          items: [
            'Only accounts created by the college may sign in, and only accounts with the administrator role can reach the scheduling functions.',
            'Faculty members are recorded in the system as scheduling records. They do not have accounts and cannot sign in.',
            'Accounts are personal. Each account is used by the person it was created for.',
            'Access may be suspended or ended by the college at any time, for example when a person leaves the role that justified the account.',
          ],
        },
      ],
    },
    {
      id: 'account-security',
      heading: '3. Account security',
      blocks: [
        {
          kind: 'list',
          items: [
            'Keep your password confidential and do not share it. Do not let anyone else use your account, and do not use anyone else’s.',
            'Use a password that is not reused from another service.',
            'Signing in ends your account’s previous sessions. If that happens unexpectedly, treat it as a signal to check the account.',
            'Sign out when you finish using a shared or unattended device. The session is held in the browser of the machine that signed in.',
            'Report a suspected compromise, a lost device that was signed in, or a password you believe someone else knows, to the college immediately.',
          ],
        },
      ],
    },
    {
      id: 'data-responsibilities',
      heading: '4. Your responsibilities for the information you enter',
      blocks: [
        {
          kind: 'list',
          items: [
            'Enter information that is accurate and complete, and keep it up to date. Faculty load, availability, qualifications, section details and room details all determine the timetables the system produces.',
            'Only change records you are authorized to change, and make sure the change reflects an institutional decision rather than a personal preference.',
            'Do not enter personal information that the scheduling purpose does not require. Free-text fields are for scheduling notes, not for case notes, health details, or other sensitive information about faculty or students.',
            'Treat faculty availability and workload information as personal information about a colleague, and use it only for scheduling.',
            'Review the resulting timetable before approving it. Approving a schedule is a decision that a person makes, not something the system does on your behalf.',
          ],
        },
      ],
    },
    {
      id: 'schedule-statuses',
      heading: '5. What the schedule statuses mean',
      blocks: [
        {
          kind: 'paragraph',
          text: 'A schedule moves through the statuses below. They are the statuses the application actually enforces:',
        },
        {
          kind: 'list',
          items: [
            'draft — a working timetable produced by the generation run or edited by hand. It is not yet an official timetable. Generating again for the same section replaces the previous draft, which is archived.',
            'approved — a draft that an authorized reviewer has accepted internally. It is still not the timetable in use.',
            'published — the timetable released for use and for printing or distribution. Only one schedule per section can be published at a time; publishing another archives the previous one.',
            'archived — a superseded timetable, kept so that earlier versions remain traceable. It is not used and cannot be published without being reviewed again.',
          ],
        },
        {
          kind: 'paragraph',
          text: 'Only a published timetable is the college’s timetable. A draft or approved schedule must not be distributed to faculty or students as though it were final, and printing is restricted to published schedules.',
        },
      ],
    },
    {
      id: 'ai-generated-schedules',
      heading: '6. AI-generated schedules require human review',
      blocks: [
        {
          kind: 'list',
          items: [
            'Timetables are produced by a constraint-based scheduling engine. It places sessions within the constraints it is given and reports a reason for any session it could not place.',
            'It is a tool, not an authority. It cannot know that a faculty member is on leave, that a subject offering has changed, or that a room is unavailable for a reason not recorded in the system.',
            'Every generated timetable must be reviewed by the people responsible for it before it is approved, and every reported conflict must be resolved before publishing.',
            'The system refuses a generated plan that conflicts with another section’s timetable in the same academic term, but no automation can guarantee a complete, correct or optimal timetable. What it produces is a proposal to be checked.',
            'A session that could not be placed is reported with its reason and must be resolved by an authorized person — by editing the timetable or correcting the underlying records.',
          ],
        },
      ],
    },
    {
      id: 'reporting-problems',
      heading: '7. Reporting errors, incidents and inaccurate information',
      blocks: [
        {
          kind: 'list',
          items: [
            'Report an incorrect timetable, a conflict the system did not detect, or a wrong faculty, room or section record to the office responsible for scheduling, so it can be corrected.',
            'Report a suspected security incident — unusual sign-ins, an account you do not recognize, a stolen device, or data that appears to have been altered — immediately to the college, and stop using the affected account until it is resolved.',
            'Report a suspected privacy incident, including personal information disclosed to someone who should not have seen it, to the college’s privacy contact. If no contact has been designated yet, raise it with the college administration office.',
          ],
        },
      ],
    },
    {
      id: 'availability-and-changes',
      heading: '8. Availability, maintenance and changes',
      blocks: [
        {
          kind: 'list',
          items: [
            'The system is provided for the college’s scheduling work and may be unavailable during maintenance, updates or a fault.',
            'Its functions, its screens and these terms may change. A material change to these terms is made by publishing a new version, which accounts are asked to accept.',
            'Work is not automatically saved while a page is open. Do not leave unsaved edits unattended; a session can also end when the account signs in elsewhere.',
          ],
        },
        {
          kind: 'pending',
          text: 'Service commitments: no uptime commitment, maintenance window or support arrangement has been agreed. The college must confirm these, including who to contact during a scheduling period.',
        },
      ],
    },
    {
      id: 'ownership',
      heading: '9. Ownership and permitted use of content',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The system, its data and the timetables it produces belong to Lapu-Lapu City College, and are used for official college purposes. Printed or exported timetables may be distributed to faculty and students for the scheduled term, and to the offices that need them.',
        },
        {
          kind: 'paragraph',
          text: 'Do not copy, extract or reuse personal information from the system for any other purpose, and do not republish college records or timetables outside the college without authorization.',
        },
        {
          kind: 'pending',
          text: 'Ownership and permitted use: the college must confirm the ownership statement above and any conditions on printing, exporting or sharing timetables.',
        },
      ],
    },
    {
      id: 'prohibited-conduct',
      heading: '10. Prohibited conduct',
      blocks: [
        {
          kind: 'list',
          items: [
            'Attempting to sign in to an account that is not yours, or to reach a function your account is not permitted to use.',
            'Sharing your credentials, or letting another person use your session, where the college has not authorized it.',
            'Changing, hiding or forging scheduling records, approvals or timetables, or approving a timetable you have not reviewed.',
            'Interfering with the system: tampering with the database or data files, attempting to overload or bypass the application, its role checks or its conflict protections, or introducing malicious content.',
            'Extracting personal information from the system in bulk, or using it for any purpose other than college scheduling.',
            'Using the system to enter personal information about a colleague or a student that the scheduling purpose does not require.',
          ],
        },
        {
          kind: 'paragraph',
          text: 'The college may suspend or end access to the system for misuse, and may take the disciplinary or legal steps its rules provide.',
        },
        {
          kind: 'pending',
          text: 'Enforcement: the college must confirm who decides on suspension, how the account holder is informed, and how a decision can be reviewed.',
        },
      ],
    },
    {
      id: 'limitations-pending-review',
      heading: '11. Limitations of responsibility — pending institutional decision',
      blocks: [
        {
          kind: 'paragraph',
          text: 'No limitation of liability, disclaimer or dispute-resolution clause has been included in these terms. Those are substantive institutional decisions and are not something this implementation or its author can decide.',
        },
        {
          kind: 'paragraph',
          text: 'Until the college adopts such terms, nothing here should be read as excluding or limiting any responsibility the college has, or as a waiver of anyone’s rights under the Data Privacy Act of 2012 or any other law.',
        },
        {
          kind: 'pending',
          text: 'Any limitation of liability, dispute-resolution clause or equivalent term must be drafted and approved by the college with legal advice, and added as a new version of these terms.',
        },
      ],
    },
    {
      id: 'contact',
      heading: '12. Contact and escalation',
      blocks: [
        {
          kind: 'paragraph',
          text: 'For questions about these terms, access to the system, or a correction to a scheduling record, contact the office responsible for class scheduling at the college.',
        },
        {
          kind: 'paragraph',
          text: 'For a privacy request or a privacy concern — including a request about the personal information held in this system — contact the college’s designated privacy contact.',
        },
        {
          kind: 'pending',
          text: 'Contacts: the college has not yet published a privacy contact or a named scheduling contact, so this page cannot list them. They must be added once the designations are official.',
        },
      ],
    },
    {
      id: 'effective-date-and-version',
      heading: '13. Effective date and version',
      blocks: [
        {
          kind: 'paragraph',
          text: 'The version and effective date of these terms are shown at the top of this page and are taken from the server, which is the authority on which revision is in force. Accepting a revision is recorded against the version you accepted, with the date and time.',
        },
        {
          kind: 'paragraph',
          text: 'If the college publishes a revised version, the version you accepted earlier no longer counts as acceptance of the current terms, and you are asked to read and accept the revision the next time you use the system.',
        },
        {
          kind: 'pending',
          text: 'These terms have no effective date yet: they are a draft prepared for the college to review, and they must not be presented as the college’s adopted terms until it adopts them.',
        },
      ],
    },
  ],
}

export const LEGAL_DOCUMENTS: LegalDocumentContent[] = [PRIVACY_POLICY, TERMS_OF_USE]

/** The essential points shown on the first-use acceptance prompt. */
export const TERMS_ACCEPTANCE_POINTS: string[] = [
  'The system is for the college’s official scheduling work, and only authorized accounts may use it.',
  'Keep your account credentials to yourself, and sign out of unattended machines.',
  'Enter accurate data, and only the personal information the scheduling purpose needs.',
  'Only a published timetable is the college’s timetable; drafts and approved schedules are not for distribution.',
  'AI-generated schedules are proposals: they must be reviewed by an authorized person before approval.',
  'Report errors, suspected security incidents and privacy concerns to the college.',
]
