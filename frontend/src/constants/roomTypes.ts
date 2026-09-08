// Canonical room types — single source of truth for both the Rooms page
// (a room's actual type) and the Subjects page (the lab_room_type a
// subject's lab sessions require). Keep both sides speaking the same
// vocabulary so the room-type-matching constraint always has matches.
export const ROOM_TYPES: { value: string; label: string }[] = [
  { value: 'lecture', label: 'Lecture' },
  { value: 'computer_lab', label: 'Computer Lab' },
  { value: 'science_lab', label: 'Science Lab' },
  { value: 'electronics_lab', label: 'Electronics Lab' },
]

// Lab-capable types, for the Subjects "Lab Room Type" dropdown
// (everything except plain 'lecture').
export const LAB_ROOM_TYPES = ROOM_TYPES.filter((t) => t.value !== 'lecture')
