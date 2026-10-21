/**
 * The system's identity, in one place.
 *
 * The product used to name itself three different ways — the login page said
 * "Faculty Scheduling System", the dashboard said "Admin Dashboard", and the
 * browser tab said "frontend". Anything that shows the system's name now reads
 * from here, so those cannot drift apart again.
 */
export const SYSTEM_ORG = 'Lapu-Lapu City College'

export const SYSTEM_NAME =
  'AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System'

/**
 * Shorter form for the browser tab, where the full title would be truncated
 * away to the point of being useless.
 */
export const SYSTEM_TAB_TITLE = `${SYSTEM_ORG} — Faculty Scheduling System`
