// Subject classification — single source of truth for the Subjects page and
// the Section editor, mirroring App\Models\Subject's constants. The values must
// stay identical to the PHP ones: subjects.classification has a check
// constraint that accepts exactly these three, and an unreviewed subject must
// stay `unclassified` rather than falling back to the permissive `minor`.
//
// The rule the whole feature rests on, read by the backend and the engine:
//   major        — one owning department; schedulable only where authorized
//   minor        — no owner; open to every department
//   unclassified — grants nothing; cannot be scheduled or generated
export type SubjectClassification = 'major' | 'minor' | 'unclassified'

export const SUBJECT_CLASSIFICATIONS: {
  value: SubjectClassification
  label: string
  hint: string
}[] = [
  {
    value: 'major',
    label: 'Major',
    hint: 'Owned by one department. Only the departments authorized for it may schedule it.',
  },
  {
    value: 'minor',
    label: 'Minor / Shared',
    hint: 'No owner. Every department may schedule it — nothing to authorize.',
  },
  {
    value: 'unclassified',
    label: 'Unclassified',
    hint: 'Not reviewed yet. It cannot be scheduled or generated until it is classified.',
  },
]

export const CLASSIFICATION_LABELS: Record<SubjectClassification, string> = {
  major: 'Major',
  minor: 'Minor / shared',
  unclassified: 'Unclassified',
}

/**
 * The classification of an API record, as one of the three known values.
 *
 * Anything unrecognised — a missing column on an unmigrated database, a value a
 * future backend adds — reads as `unclassified`, the fail-closed outcome: it
 * grants nothing, instead of being displayed as someone's major or as a shared
 * subject every department may take.
 */
export function classificationOf(value: unknown): SubjectClassification {
  return value === 'major' || value === 'minor' ? value : 'unclassified'
}
