import json

rooms = [
    {"id": 1, "name": "R101", "type": "lecture", "capacity": 40},
    {"id": 2, "name": "LAB1", "type": "computer_lab", "capacity": 30},
    {"id": 4, "name": "ROOM2", "type": "lecture", "capacity": 2},
]

with open('data/rooms.json', 'w') as f:
    json.dump(rooms, f, indent=2)

print(f"Saved {len(rooms)} rooms to data/rooms.json")
