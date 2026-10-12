// Building → Floor → Room hierarchy
// Edit this file to add/remove buildings, floors, or rooms.
const FACILITY_HIERARCHY = {
    'Main Building': {
        'Ground Floor': ['Reception', 'Conference Room A', 'Conference Room B', 'Boardroom'],
        'Floor 1': ['Office 101', 'Office 102', 'Office 103', 'Meeting Room 1A', 'Meeting Room 1B'],
        'Floor 2': ['Office 201', 'Office 202', 'Office 203', 'Meeting Room 2A'],
        'Floor 3': ['Office 301', 'Office 302', 'Training Room'],
    },
    'Annex Building': {
        'Ground Floor': ['Lab 1', 'Lab 2', 'Workshop'],
        'Floor 1': ['Lab 3', 'Lab 4', 'Studio'],
        'Floor 2': ['Server Room', 'Storage'],
    },

};

export function getBuildings() {
    return Object.keys(FACILITY_HIERARCHY);
}

export function getFloors(building) {
    if (!building || !FACILITY_HIERARCHY[building]) return [];
    return Object.keys(FACILITY_HIERARCHY[building]);
}

export function getRooms(building, floor) {
    if (!building || !floor) return [];
    return FACILITY_HIERARCHY[building]?.[floor] || [];
}

export function formatLocation(building, floor, room) {
    const parts = [building, floor, room].filter(Boolean);
    return parts.length > 0 ? parts.join(' → ') : '';
}

export default FACILITY_HIERARCHY;
