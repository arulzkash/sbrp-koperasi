<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const props = defineProps({
    trace: {
        type: Object,
        required: true,
    },
    direction: {
        type: String,
        default: "morning",
    },
    session: {
        type: String,
        default: null,
    },
    availableSessions: {
        type: Array,
        default: () => [],
    },
});

const selectedDirection = ref(props.direction ?? "morning");
const selectedSession = ref(props.session ?? props.availableSessions[0] ?? "");
const displayMode = ref("story");
const activeStoryIndex = ref(0);
const detailEventIndex = ref(0);
const isPlaying = ref(false);
const playbackMs = ref(1500);
const routeLearningMode = ref("nearest_neighbor");
const improvementLearningMode = ref("local_move");
const selectedTeachingTripId = ref(null);
const nearestStepIndex = ref(0);
const twoOptMoveIndex = ref(0);

let playbackTimer = null;
let routeLeafletMap = null;
let routeLeafletLayers = null;
let routeMapCameraKey = null;
let routeMapUserAdjusted = false;
let routeMapProgrammaticCamera = false;

const routeMapElement = ref(null);

const palette = [
    "var(--trip-1)",
    "var(--trip-2)",
    "var(--trip-3)",
    "var(--trip-4)",
    "var(--trip-5)",
    "var(--trip-6)",
    "var(--trip-7)",
    "var(--trip-8)",
];

const events = computed(() => props.trace?.events ?? []);
const trips = computed(() => props.trace?.trips ?? []);
const students = computed(() => props.trace?.students ?? []);
const school = computed(() => props.trace?.school ?? null);
const summary = computed(() => props.trace?.summary ?? {});

const studentById = computed(() => {
    const map = new Map();
    students.value.forEach((student) => map.set(Number(student.id), student));
    return map;
});

const tripById = computed(() => {
    const map = new Map();
    trips.value.forEach((trip) => map.set(Number(trip.id), trip));
    return map;
});

const normalizeTime = (time) => {
    if (!time) return "";
    const parts = String(time).split(":");

    return [parts[0] ?? "00", parts[1] ?? "00", parts[2] ?? "00"]
        .map((part) => part.padStart(2, "0"))
        .join(":");
};

const formatTime = (time) => (time ? normalizeTime(time).substring(0, 5) : "-");

const formatKm = (value) => {
    if (value === null || value === undefined) return "-";
    return `${Number(value).toFixed(3)} km`;
};

const formatSignedKm = (value) => {
    if (value === null || value === undefined) return "-";
    const number = Number(value);
    const sign = number > 0 ? "+" : "";

    return `${sign}${number.toFixed(3)} km`;
};

const formatDistanceChange = (value) => {
    const kilometers = Math.abs(Number(value ?? 0));
    const meters = kilometers * 1000;

    if (meters < 1000) {
        const precision = meters > 0 && meters < 10 ? 1 : 0;
        return `${meters.toFixed(precision)} m`;
    }

    return formatKm(kilometers);
};

const edgeSummary = (edges = []) =>
    edges.map((edge) => `${edge.from?.label ?? "?"} → ${edge.to?.label ?? "?"}`).join("; ");

const shortTripLabel = (label) => {
    if (!label) return "Rit";

    return label
        .replace("TOYOTA/KIJANG SUPER KF 40 SHORT/MINIBUS", "Toyota Minibus")
        .replace("SUZUKI/CARRY ST 130 FUTURA/MINIBUS", "Suzuki Carry")
        .replace("DAIHATSU/840IRV-ZMDEJJ HJ/MINIBUS", "Daihatsu Minibus");
};

const findEventIndex = (predicate, fallback = 0) => {
    const index = events.value.findIndex(predicate);
    return index >= 0 ? index : fallback;
};

const namesFromIds = (ids = [], limit = 3) =>
    ids
        .slice(0, limit)
        .map((id) => studentById.value.get(Number(id))?.name)
        .filter(Boolean)
        .join(", ");

const storyChapters = computed(() => {
    const fallback = Math.max(events.value.length - 1, 0);
    const filterIndex = findEventIndex((event) => event.phase === "filter", 0);
    const sortIndex = findEventIndex((event) => event.phase === "sort", filterIndex);
    const insertionIndex = findEventIndex(
        (event) => event.phase === "insertion" && event.score_rows?.length,
        findEventIndex((event) => event.phase === "insertion", sortIndex),
    );
    const improveIndex = findEventIndex(
        (event) =>
            ["local_move", "rebalance"].includes(event.phase) &&
            (event.source_trip_id || event.destination_trip_id),
        findEventIndex((event) => ["local_move", "rebalance"].includes(event.phase), insertionIndex),
    );
    const routeIndex = findEventIndex(
        (event) => event.phase === "nearest_neighbor",
        insertionIndex,
    );
    const finalIndex = findEventIndex(
        (event) => event.phase === "road_geometry",
        findEventIndex((event) => event.phase === "two_opt", fallback),
    );

    const sortEvent = events.value[sortIndex];
    const firstNames = namesFromIds(sortEvent?.sorted_student_ids ?? []);

    return [
        {
            id: "filter",
            short: "Filter",
            title: "Filter siswa dan rit aktif",
            eventIndex: filterIndex,
            intent: "Sistem mengambil siswa lunas, jenis layanan sesuai arah rute, koordinat valid, dan rit armada yang aktif.",
            takeaway: `${summary.value.eligible_students ?? 0} siswa masuk kandidat; ${summary.value.active_trips ?? 0} rit tersedia.`,
        },
        {
            id: "priority",
            short: "Prioritas",
            title: "Proses siswa terjauh lebih dulu",
            eventIndex: sortIndex,
            intent: "Siswa diurutkan dari jarak terjauh ke sekolah agar titik yang lebih sulit ditempatkan tidak tertinggal di akhir.",
            takeaway: firstNames ? `Contoh awal antrean: ${firstNames}.` : "Antrean siswa siap diproses.",
        },
        {
            id: "score",
            short: "Skor",
            title: "Bandingkan skor tiap rit",
            eventIndex: insertionIndex,
            intent: "Untuk satu siswa kandidat, sistem menghitung biaya sisip, penalti kapasitas, dan penalti outlier pada tiap rit.",
            takeaway: "Rit dengan skor total terkecil dipilih.",
        },
        {
            id: "improve",
            short: "Rapikan",
            title: "Perbaiki alokasi jika perlu",
            eventIndex: improveIndex,
            intent: "Local move dan soft rebalance hanya berjalan jika memindahkan siswa membuat rute lebih pendek atau beban rit lebih masuk akal.",
            takeaway: "Langkah ini lunak: tidak memaksa semua rit harus sama penuh.",
        },
        {
            id: "route",
            short: "Urutan",
            title: "Susun urutan jemput/antar",
            eventIndex: routeIndex,
            intent: props.trace.direction === "morning"
                ? "Nearest neighbor mulai dari pool armada, lalu memilih siswa terdekat berikutnya sampai berakhir di sekolah."
                : "Nearest neighbor mulai dari sekolah, lalu memilih siswa terdekat berikutnya.",
            takeaway: "Urutan awal kemudian diperiksa lagi dengan 2-opt.",
        },
        {
            id: "final",
            short: "Final",
            title: "Finalisasi rute untuk peta",
            eventIndex: finalIndex,
            intent: "Urutan hasil heuristik menjadi dasar tampilan rute. OSRM dipakai setelahnya untuk menggambar geometri jalan di dashboard.",
            takeaway: `${summary.value.assigned_students ?? 0} siswa teralokasi pada trace ini.`,
        },
    ];
});

const activeChapter = computed(() => storyChapters.value[activeStoryIndex.value] ?? storyChapters.value[0]);

const chapterIdByPhase = {
    filter: "filter",
    sort: "priority",
    insertion: "score",
    local_move: "improve",
    rebalance: "improve",
    nearest_neighbor: "route",
    two_opt: "route",
    road_geometry: "final",
};

const currentEventIndex = computed(() =>
    displayMode.value === "story"
        ? activeChapter.value?.eventIndex ?? 0
        : detailEventIndex.value,
);

const currentEvent = computed(() => events.value[currentEventIndex.value] ?? events.value[0] ?? null);

const visualChapter = computed(() => {
    if (displayMode.value === "story") return activeChapter.value;

    const chapterId = chapterIdByPhase[currentEvent.value?.phase] ?? "filter";
    return storyChapters.value.find((chapter) => chapter.id === chapterId) ?? activeChapter.value;
});

const visualChapterIndex = computed(() =>
    Math.max(storyChapters.value.findIndex((chapter) => chapter.id === visualChapter.value?.id), 0),
);

const activeImprovementEvent = computed(() => {
    if (displayMode.value === "detail" && visualChapter.value?.id === "improve") {
        return currentEvent.value;
    }

    const phase = improvementLearningMode.value;
    const phaseEvents = events.value.filter((event) => event.phase === phase);

    return phaseEvents.find((event) => event.source_trip_id && event.destination_trip_id)
        ?? phaseEvents[0]
        ?? null;
});

const activeImprovementPhase = computed(() =>
    activeImprovementEvent.value?.phase ?? improvementLearningMode.value,
);

const teachingEvent = computed(() =>
    visualChapter.value?.id === "improve" ? activeImprovementEvent.value : currentEvent.value,
);

const currentTripLoads = computed(() => teachingEvent.value?.trip_loads ?? {});

const selectedScore = computed(() =>
    currentEvent.value?.score_rows?.find((row) => row.selected) ?? null,
);

const simplifiedScoreRows = computed(() => {
    const rows = (currentEvent.value?.score_rows ?? [])
        .filter((row) => row.available && row.score !== null && row.score !== undefined)
        .sort((a, b) => Number(a.score) - Number(b.score));

    if (rows.length === 0) return [];

    const selected = rows.find((row) => row.selected);
    if (!selected) return rows.slice(0, 3);

    return [
        selected,
        ...rows.filter((row) => row.trip_id !== selected.trip_id).slice(0, 2),
    ];
});

const hiddenScoreCount = computed(() =>
    Math.max((currentEvent.value?.score_rows?.length ?? 0) - simplifiedScoreRows.value.length, 0),
);

const priorityStudents = computed(() => {
    const ids = currentEvent.value?.sorted_student_ids ?? [];

    return ids
        .slice(0, 5)
        .map((id, index) => ({
            ...studentById.value.get(Number(id)),
            rank: index + 1,
        }))
        .filter((student) => student.id);
});

const maxPriorityDistance = computed(() =>
    Math.max(...priorityStudents.value.map((student) => Number(student.school_distance_km ?? 0)), 1),
);

const priorityBarWidth = (student) =>
    `${Math.max((Number(student.school_distance_km ?? 0) / maxPriorityDistance.value) * 100, 8)}%`;

const scoreComponentWidth = (value, row) => {
    if (!row?.score || value === null || value === undefined) return "0%";
    return `${Math.max((Number(value) / Number(row.score)) * 100, 3)}%`;
};

const improvementTrips = computed(() => {
    const event = activeImprovementEvent.value;
    if (!event) return [];

    return [
        { role: "Sumber", tripId: event.source_trip_id },
        { role: "Tujuan", tripId: event.destination_trip_id },
    ]
        .filter((item) => item.tripId)
        .map((item) => {
            const trip = tripById.value.get(Number(item.tripId));
            const afterLoad = currentTripLoads.value?.[item.tripId]?.length ?? 0;
            const key = item.role === "Sumber" ? "source" : "destination";
            const explicitBeforeLoad = event[`${key}_load_before`];
            const explicitAfterLoad = event[`${key}_load_after`];
            const beforeLoad = explicitBeforeLoad ?? (item.role === "Sumber" ? afterLoad + 1 : Math.max(afterLoad - 1, 0));

            return {
                ...item,
                trip,
                beforeLoad,
                afterLoad: explicitAfterLoad ?? afterLoad,
                capacity: event[`${key}_capacity`] ?? trip?.capacity ?? 1,
            };
        });
});

const visibleRouteStudents = computed(() =>
    focusRouteStudentIds.value
        .slice(0, 20)
        .map((id, index) => ({
            ...studentById.value.get(Number(id)),
            order: index + 1,
        }))
        .filter((student) => student.id),
);

const routeTripOptions = computed(() =>
    trips.value
        .map((trip) => {
            const nearestEvent = events.value.find(
                (event) => event.phase === "nearest_neighbor" && Number(event.active_trip_id) === Number(trip.id),
            );
            const optimizeEvent = events.value.find(
                (event) => event.phase === "two_opt" && Number(event.active_trip_id) === Number(trip.id),
            );
            const routeIds = (nearestEvent?.route_student_ids ?? optimizeEvent?.route_before_ids ?? []).map(Number);
            const routeStudents = routeIds.map((id) => studentById.value.get(id)).filter(Boolean);
            const uniqueCoordinateCount = new Set(
                routeStudents.map((student) => `${Number(student.latitude).toFixed(5)}:${Number(student.longitude).toFixed(5)}`),
            ).size;
            const coordinateClarity = routeStudents.length > 0 ? uniqueCoordinateCount / routeStudents.length : 0;
            const acceptedMoveCount = Number(optimizeEvent?.accepted_move_count ?? optimizeEvent?.accepted_moves?.length ?? 0);
            const saving = Math.abs(Number(optimizeEvent?.distance_delta_km ?? 0));
            const teachingScore = (acceptedMoveCount > 0 ? 1000 : 0)
                + coordinateClarity * 250
                + Math.min(saving * 150, 120)
                + Math.min(acceptedMoveCount, 5) * 10
                + Math.min(routeStudents.length, 20);

            return {
                id: Number(trip.id),
                trip,
                studentCount: routeStudents.length,
                acceptedMoveCount,
                coordinateClarity,
                teachingScore,
            };
        })
        .filter((option) => option.studentCount > 0)
        .sort((a, b) => b.teachingScore - a.teachingScore || a.id - b.id),
);

const defaultTeachingTripId = computed(() => routeTripOptions.value[0]?.id ?? null);

const focusTripId = computed(() => {
    if (!currentEvent.value) return null;

    if (displayMode.value === "story" && visualChapter.value?.id === "route") {
        return selectedTeachingTripId.value ?? defaultTeachingTripId.value;
    }

    if (currentEvent.value.selected_trip_id) return Number(currentEvent.value.selected_trip_id);
    if (currentEvent.value.active_trip_id) return Number(currentEvent.value.active_trip_id);
    if (selectedScore.value?.trip_id) return Number(selectedScore.value.trip_id);

    const finalRoutes = currentEvent.value.final_routes ?? {};
    const firstFinalTripId = Object.keys(finalRoutes)[0];
    if (firstFinalTripId) return Number(firstFinalTripId);

    const firstLoadedTripId = Object.entries(currentTripLoads.value).find(
        ([, studentIds]) => studentIds.length > 0,
    )?.[0];

    return firstLoadedTripId ? Number(firstLoadedTripId) : trips.value[0]?.id ?? null;
});

const focusTrip = computed(() =>
    focusTripId.value ? tripById.value.get(Number(focusTripId.value)) : null,
);

const effectiveRouteLearningMode = computed(() => {
    if (displayMode.value === "detail") {
        return currentEvent.value?.phase === "two_opt" ? "two_opt" : "nearest_neighbor";
    }

    return routeLearningMode.value;
});

const nearestNeighborEvent = computed(() => {
    const tripId = focusTripId.value;

    return events.value.find(
        (event) => event.phase === "nearest_neighbor" && Number(event.active_trip_id) === Number(tripId),
    ) ?? null;
});

const twoOptEvent = computed(() => {
    const tripId = focusTripId.value;

    return events.value.find(
        (event) => event.phase === "two_opt" && Number(event.active_trip_id) === Number(tripId),
    ) ?? null;
});

const nearestSteps = computed(() => nearestNeighborEvent.value?.steps ?? []);
const activeNearestStepIndex = computed(() =>
    Math.min(Math.max(nearestStepIndex.value, 0), Math.max(nearestSteps.value.length - 1, 0)),
);
const activeNearestStep = computed(() => nearestSteps.value[activeNearestStepIndex.value] ?? null);
const nearestCandidateRows = computed(() => (activeNearestStep.value?.candidate_rows ?? []).slice(0, 3));

const twoOptMoves = computed(() => twoOptEvent.value?.accepted_moves ?? []);
const activeTwoOptMoveIndex = computed(() =>
    Math.min(Math.max(twoOptMoveIndex.value, 0), Math.max(twoOptMoves.value.length - 1, 0)),
);
const activeTwoOptMove = computed(() => twoOptMoves.value[activeTwoOptMoveIndex.value] ?? null);

const activeRouteTraceEvent = computed(() =>
    effectiveRouteLearningMode.value === "two_opt" ? twoOptEvent.value : nearestNeighborEvent.value,
);

const routeLearningStudentIds = computed(() => {
    if (visualChapter.value?.id !== "route") return null;

    if (effectiveRouteLearningMode.value === "two_opt") {
        return (activeTwoOptMove.value?.route_after_ids ?? twoOptEvent.value?.route_student_ids ?? []).map(Number);
    }

    return (activeNearestStep.value?.route_after_ids ?? nearestNeighborEvent.value?.route_student_ids ?? []).map(Number);
});

const activeStudent = computed(() => {
    const studentId = teachingEvent.value?.active_student_id;
    return studentId ? studentById.value.get(Number(studentId)) : null;
});

const assignedByStudentId = computed(() => {
    const map = new Map();

    Object.entries(currentTripLoads.value).forEach(([tripId, studentIds]) => {
        studentIds.forEach((studentId) => {
            map.set(Number(studentId), Number(tripId));
        });
    });

    return map;
});

const getRouteIdsForTrip = (trip) => {
    const event = currentEvent.value;
    if (!event || !trip) return [];

    if (
        visualChapter.value?.id === "route" &&
        Number(trip.id) === Number(focusTripId.value) &&
        Array.isArray(routeLearningStudentIds.value)
    ) {
        return routeLearningStudentIds.value;
    }

    if (Number(event.active_trip_id) === Number(trip.id) && Array.isArray(event.route_student_ids)) {
        return event.route_student_ids;
    }

    if (event.final_routes?.[trip.id]) {
        return event.final_routes[trip.id];
    }

    return currentTripLoads.value?.[trip.id] ?? [];
};

const focusRouteStudentIds = computed(() =>
    focusTrip.value ? getRouteIdsForTrip(focusTrip.value).map((id) => Number(id)) : [],
);

const highlightedStudentIds = computed(() => {
    const ids = new Set(focusRouteStudentIds.value);

    if (activeStudent.value?.id) {
        ids.add(Number(activeStudent.value.id));
    }

    return ids;
});

const routeOrderByStudentId = computed(() => {
    const order = new Map();
    focusRouteStudentIds.value.forEach((studentId, index) => {
        order.set(Number(studentId), index + 1);
    });

    return order;
});

const allCoordinates = computed(() => {
    const coordinates = [];

    const addStudent = (student) => {
        if (student) coordinates.push([student.latitude, student.longitude]);
    };

    const addTrip = (trip) => {
        if (trip) coordinates.push([trip.fleet.base_latitude, trip.fleet.base_longitude]);
    };

    if (school.value) {
        coordinates.push([school.value.latitude, school.value.longitude]);
    }

    if (visualChapter.value?.id === "priority") {
        priorityStudents.value.forEach(addStudent);
    } else if (visualChapter.value?.id === "score") {
        addStudent(activeStudent.value);
        simplifiedScoreRows.value.forEach((row) => addTrip(tripById.value.get(Number(row.trip_id))));
    } else if (visualChapter.value?.id === "improve") {
        addStudent(activeStudent.value);
        improvementTrips.value.forEach((item) => addTrip(item.trip));
    } else if (["route", "final"].includes(visualChapter.value?.id)) {
        focusRouteStudentIds.value.forEach((id) => addStudent(studentById.value.get(Number(id))));
        addTrip(focusTrip.value);
    } else {
        students.value.forEach(addStudent);
        trips.value.forEach(addTrip);
    }

    return coordinates.filter(([lat, lng]) => Number.isFinite(Number(lat)) && Number.isFinite(Number(lng)));
});

const mapBounds = computed(() => {
    const coordinates = allCoordinates.value;

    if (coordinates.length === 0) {
        return {
            minLat: -6.84,
            maxLat: -6.81,
            minLng: 107.62,
            maxLng: 107.66,
        };
    }

    const lats = coordinates.map(([lat]) => Number(lat));
    const lngs = coordinates.map(([, lng]) => Number(lng));
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);
    const latPad = Math.max((maxLat - minLat) * 0.16, 0.003);
    const lngPad = Math.max((maxLng - minLng) * 0.16, 0.003);

    return {
        minLat: minLat - latPad,
        maxLat: maxLat + latPad,
        minLng: minLng - lngPad,
        maxLng: maxLng + lngPad,
    };
});

const projectPoint = (lat, lng) => {
    const bounds = mapBounds.value;
    const width = 1000;
    const height = 560;
    const padding = 52;
    const latSpan = bounds.maxLat - bounds.minLat || 1;
    const lngSpan = bounds.maxLng - bounds.minLng || 1;

    return {
        x: padding + ((Number(lng) - bounds.minLng) / lngSpan) * (width - padding * 2),
        y: padding + ((bounds.maxLat - Number(lat)) / latSpan) * (height - padding * 2),
    };
};

const getRoutePointsForTrip = (trip) => {
    if (!trip) return [];

    const routeStudents = getRouteIdsForTrip(trip)
        .map((studentId) => studentById.value.get(Number(studentId)))
        .filter(Boolean);

    if (routeStudents.length === 0) return [];

    const start =
        props.trace.direction === "morning"
            ? {
                  latitude: trip.fleet.base_latitude,
                  longitude: trip.fleet.base_longitude,
              }
            : school.value;

    const points = [start, ...routeStudents];

    const nearestNeighborFinished =
        activeNearestStep.value && activeNearestStepIndex.value === nearestSteps.value.length - 1;
    const shouldAppendSchool =
        visualChapter.value?.id === "final" ||
        effectiveRouteLearningMode.value === "two_opt" ||
        nearestNeighborFinished;

    if (props.trace.direction === "morning" && school.value && shouldAppendSchool) {
        points.push(school.value);
    }

    return points.filter(Boolean);
};

const focusRoutePoints = computed(() =>
    focusTrip.value ? getRoutePointsForTrip(focusTrip.value) : [],
);

const routePointsFromIds = (ids = [], includeMorningSchool = true) => {
    if (!focusTrip.value) return [];

    const start = props.trace.direction === "morning"
        ? {
              type: "fleet_base",
              label: "Pool armada",
              latitude: focusTrip.value.fleet.base_latitude,
              longitude: focusTrip.value.fleet.base_longitude,
          }
        : school.value;
    const points = [
        start,
        ...ids.map((id) => studentById.value.get(Number(id))).filter(Boolean),
    ];

    if (props.trace.direction === "morning" && includeMorningSchool && school.value) {
        points.push(school.value);
    }

    return points.filter(Boolean);
};

const twoOptBeforePoints = computed(() =>
    routePointsFromIds(activeTwoOptMove.value?.route_before_ids ?? twoOptEvent.value?.route_before_ids ?? []),
);

const routeMapPoints = computed(() =>
    visualChapter.value?.id === "route" &&
    effectiveRouteLearningMode.value === "two_opt" &&
    activeTwoOptMove.value
        ? twoOptBeforePoints.value
        : focusRoutePoints.value,
);

const routeMapStudentIds = computed(() =>
    visualChapter.value?.id === "route" &&
    effectiveRouteLearningMode.value === "two_opt" &&
    activeTwoOptMove.value
        ? activeTwoOptMove.value.route_before_ids.map(Number)
        : focusRouteStudentIds.value,
);

const routeSequenceStudents = (ids = [], limit = 12) =>
    ids
        .slice(0, limit)
        .map((id, index) => ({
            ...studentById.value.get(Number(id)),
            order: index + 1,
        }))
        .filter((student) => student.id);

const routeSequenceContext = (ids = []) => {
    const move = activeTwoOptMove.value;
    const students = ids
        .map((id, index) => ({
            ...studentById.value.get(Number(id)),
            order: index + 1,
        }))
        .filter((student) => student.id);

    if (!move) {
        return { students: students.slice(0, 12), hiddenBefore: 0, hiddenAfter: Math.max(students.length - 12, 0) };
    }

    const firstIndex = Math.max(Number(move.start_position) - 2, 0);
    const endIndex = Math.min(Number(move.end_position) + 1, students.length);

    return {
        students: students.slice(firstIndex, endIndex),
        hiddenBefore: firstIndex,
        hiddenAfter: Math.max(students.length - endIndex, 0),
    };
};

const twoOptBeforeContext = computed(() => routeSequenceContext(activeTwoOptMove.value?.route_before_ids ?? []));
const twoOptAfterContext = computed(() => routeSequenceContext(activeTwoOptMove.value?.route_after_ids ?? []));

const twoOptSegmentStudentIds = computed(() =>
    new Set((activeTwoOptMove.value?.segment_after_ids ?? []).map(Number)),
);

const twoOptSegmentBeforeStudentIds = computed(() =>
    new Set((activeTwoOptMove.value?.segment_before_ids ?? []).map(Number)),
);

const twoOptStartStudent = computed(() => {
    const move = activeTwoOptMove.value;
    if (!move) return null;

    return studentById.value.get(Number(move.route_before_ids?.[Number(move.start_position) - 1])) ?? null;
});

const twoOptEndStudent = computed(() => {
    const move = activeTwoOptMove.value;
    if (!move) return null;

    return studentById.value.get(Number(move.route_before_ids?.[Number(move.end_position) - 1])) ?? null;
});

const distanceMeters = (first, second) => {
    const latitudeAverage = ((Number(first.latitude) + Number(second.latitude)) / 2) * (Math.PI / 180);
    const latitudeDistance = (Number(first.latitude) - Number(second.latitude)) * 111320;
    const longitudeDistance = (Number(first.longitude) - Number(second.longitude)) * 111320 * Math.cos(latitudeAverage);

    return Math.hypot(latitudeDistance, longitudeDistance);
};

const clusterRouteStudents = (studentIds = []) => {
    const clusters = [];

    studentIds.forEach((studentId) => {
        const student = studentById.value.get(Number(studentId));
        if (!student) return;

        const cluster = clusters.find((item) => distanceMeters(item.anchor, student) <= 6);
        if (cluster) {
            cluster.students.push(student);
            return;
        }

        clusters.push({ anchor: student, students: [student] });
    });

    return clusters;
};

const overlappingRouteGroupCount = computed(() =>
    clusterRouteStudents(routeMapStudentIds.value).filter((cluster) => cluster.students.length > 1).length,
);

const pointString = (points) =>
    points
        .map((point) => {
            const projected = projectPoint(point.latitude, point.longitude);
            return `${projected.x},${projected.y}`;
        })
        .join(" ");

const tripColor = (tripId) => {
    const index = trips.value.findIndex((trip) => Number(trip.id) === Number(tripId));
    return palette[Math.max(index, 0) % palette.length];
};

const studentFill = (studentId) => {
    const tripId = assignedByStudentId.value.get(Number(studentId));
    return tripId ? tripColor(tripId) : "var(--color-student-idle)";
};

const isStudentHighlighted = (studentId) =>
    highlightedStudentIds.value.has(Number(studentId));

const studentRadius = (student) => {
    if (activeStudent.value?.id === student.id) return 10;
    if (isStudentHighlighted(student.id)) return 6.5;
    return displayMode.value === "story" ? 3 : 4;
};

const studentOpacity = (student) => {
    if (activeStudent.value?.id === student.id) return 1;
    if (isStudentHighlighted(student.id)) return 0.9;
    return displayMode.value === "story" ? 0.18 : 0.35;
};

const getTripStudents = (tripId) =>
    (currentTripLoads.value?.[tripId] ?? [])
        .map((studentId) => studentById.value.get(Number(studentId)))
        .filter(Boolean);

const routeMicroProgress = computed(() => {
    if (effectiveRouteLearningMode.value === "two_opt") {
        return twoOptMoves.value.length
            ? `Perubahan ${activeTwoOptMoveIndex.value + 1} / ${twoOptMoves.value.length}`
            : "Tidak ada perubahan yang diterima";
    }

    return nearestSteps.value.length
        ? `Pilihan ${activeNearestStepIndex.value + 1} / ${nearestSteps.value.length}`
        : "Trace pilihan belum tersedia";
});

const lessonStageTitle = computed(() => {
    if (visualChapter.value?.id === "improve") {
        return activeImprovementPhase.value === "rebalance"
            ? "4B · Seimbangkan beban secara lunak"
            : "4A · Pindahkan siswa jika rute memendek";
    }

    if (visualChapter.value?.id !== "route") return visualChapter.value?.title;

    return effectiveRouteLearningMode.value === "two_opt"
        ? "5B · Perbaiki urutan dengan 2-opt"
        : "5A · Pilih titik terdekat";
});

const lessonStageIntent = computed(() => {
    if (visualChapter.value?.id === "improve") {
        return activeImprovementPhase.value === "rebalance"
            ? "Satu siswa boleh berpindah dari rit sangat penuh ke rit yang longgar selama sumber tetap aman dan tambahan jarak masih di bawah batas."
            : "Satu siswa dicoba pada rit lain yang masih muat. Perpindahan diterima hanya jika total jarak gabungan dua rit berkurang.";
    }

    if (visualChapter.value?.id !== "route") return visualChapter.value?.intent;

    return effectiveRouteLearningMode.value === "two_opt"
        ? "Sistem mencoba membalik segmen rute. Perubahan hanya diterima jika jarak total menjadi lebih pendek."
        : "Dari posisi saat ini, sistem membandingkan siswa yang belum dikunjungi lalu memilih jarak paling kecil.";
});

const routeDecisionTitle = computed(() => {
    if (effectiveRouteLearningMode.value === "nearest_neighbor") {
        const step = activeNearestStep.value;
        if (!step) return "Titik terdekat dipilih satu per satu.";

        return `Dari ${step.current_point?.label ?? "posisi saat ini"}, ${step.selected_student_name} paling dekat.`;
    }

    const move = activeTwoOptMove.value;
    if (!move) return "Urutan NN sudah local optimum; tidak ada pembalikan yang lebih pendek.";

    return `Posisi ${move.start_position}–${move.end_position} dibalik; jarak berkurang ${formatDistanceChange(move.distance_delta_km)}.`;
});

const improvementDecisionTitle = computed(() => {
    const event = activeImprovementEvent.value;

    if (!event?.source_trip_id || !event?.destination_trip_id) {
        return activeImprovementPhase.value === "rebalance"
            ? "Tidak ada pasangan rit yang memenuhi batas soft rebalance."
            : "Tidak ada perpindahan siswa yang memendekkan jarak gabungan.";
    }

    if (activeImprovementPhase.value === "rebalance") {
        return `Beban rit dibuat lebih seimbang dengan tambahan ${formatKm(event.extra_distance_km ?? event.distance_delta_km ?? 0)}.`;
    }

    return `Siswa dipindahkan; jarak gabungan berkurang ${formatKm(Math.abs(Number(event.distance_delta_km ?? 0)))}.`;
});

const decisionTitle = computed(() => {
    if (visualChapter.value?.id === "route") return routeDecisionTitle.value;
    if (visualChapter.value?.id === "improve") return improvementDecisionTitle.value;

    return visualChapter.value?.takeaway;
});

const twoOptSavingPercent = computed(() => {
    const move = activeTwoOptMove.value;
    const before = Number(move?.distance_before_km ?? 0);
    const saving = Math.abs(Number(move?.distance_delta_km ?? 0));

    return before > 0 ? (saving / before) * 100 : 0;
});

const chapterFacts = computed(() => {
    const event = teachingEvent.value;
    const chapter = visualChapter.value;

    if (!event || !chapter) return [];

    if (chapter.id === "filter") {
        return [
            { label: "Siswa lolos", value: String(summary.value.eligible_students ?? 0) },
            { label: "Koordinat tidak valid", value: String(summary.value.invalid_students ?? 0) },
            { label: "Rit tersedia", value: String(summary.value.active_trips ?? 0) },
        ];
    }

    if (chapter.id === "priority") {
        const firstStudent = priorityStudents.value[0];

        return [
            { label: "Diproses pertama", value: firstStudent?.name ?? "-" },
            { label: "Jarak ke sekolah", value: formatKm(firstStudent?.school_distance_km) },
            { label: "Total antrean", value: String(summary.value.eligible_students ?? 0) },
        ];
    }

    if (chapter.id === "score" && selectedScore.value) {
        return [
            { label: "Siswa kandidat", value: activeStudent.value?.name ?? "-" },
            { label: "Rit terpilih", value: shortTripLabel(selectedScore.value.trip_label) },
            { label: "Skor", value: Number(selectedScore.value.score).toFixed(3) },
        ];
    }

    if (chapter.id === "route") {
        if (effectiveRouteLearningMode.value === "nearest_neighbor") {
            const step = activeNearestStep.value;

            return [
                { label: "Rit contoh", value: focusTrip.value ? shortTripLabel(focusTrip.value.label) : "-" },
                { label: "Posisi saat ini", value: step?.current_point?.label ?? "-" },
                { label: "Titik dipilih", value: step?.selected_student_name ?? "-" },
                { label: "Jarak terdekat", value: formatKm(step?.selected_distance_km) },
            ];
        }

        const move = activeTwoOptMove.value;

        return [
            { label: "Rit contoh", value: focusTrip.value ? shortTripLabel(focusTrip.value.label) : "-" },
            { label: "Posisi dibalik", value: move ? `${move.start_position}–${move.end_position}` : "Tidak ada" },
            { label: "Sebelum → sesudah", value: move ? `${formatKm(move.distance_before_km)} → ${formatKm(move.distance_after_km)}` : formatKm(twoOptEvent.value?.distance_after_km) },
            { label: "Penghematan", value: move ? `${formatDistanceChange(move.distance_delta_km)} (${twoOptSavingPercent.value > 0 && twoOptSavingPercent.value < 0.1 ? "<0.1" : twoOptSavingPercent.value.toFixed(1)}%)` : "0 m" },
        ];
    }

    if (chapter.id === "improve") {
        if (activeImprovementPhase.value === "rebalance") {
            return [
                { label: "Siswa dipindah", value: activeStudent.value?.name ?? "Tidak ada" },
                { label: "Batas tambahan", value: formatKm(event.max_extra_distance_km ?? (props.trace.direction === "morning" ? 1.5 : 2)) },
                { label: "Dampak jarak", value: event.extra_distance_km !== undefined ? formatSignedKm(event.extra_distance_km) : "Tidak berubah" },
            ];
        }

        return [
            { label: "Sumber", value: event.source_trip_id ? shortTripLabel(tripById.value.get(Number(event.source_trip_id))?.label) : "-" },
            { label: "Tujuan", value: event.destination_trip_id ? shortTripLabel(tripById.value.get(Number(event.destination_trip_id))?.label) : "-" },
            { label: "Delta", value: event.distance_delta_km !== undefined ? formatSignedKm(event.distance_delta_km) : formatKm(event.extra_distance_km) },
        ];
    }

    if (chapter.id === "final") {
        return [
            { label: "Siswa teralokasi", value: String(summary.value.assigned_students ?? 0) },
            { label: "Rit digunakan", value: String(summary.value.active_trips ?? 0) },
            { label: "Arah", value: props.trace.direction === "morning" ? "Rumah → sekolah" : "Sekolah → rumah" },
        ];
    }

    return [
        { label: "Siswa eligible", value: String(summary.value.eligible_students ?? 0) },
        { label: "Rit aktif", value: String(summary.value.active_trips ?? 0) },
        { label: "Event trace", value: String(events.value.length) },
    ];
});

const progressText = computed(() => {
    if (displayMode.value === "story") {
        return `${activeStoryIndex.value + 1} / ${storyChapters.value.length}`;
    }

    return `${detailEventIndex.value + 1} / ${events.value.length}`;
});

const applyFilters = () => {
    stopPlayback();

    const query = {
        direction: selectedDirection.value,
    };

    if (selectedDirection.value === "afternoon" && selectedSession.value) {
        query.session = selectedSession.value;
    }

    router.get(route("admin.routes.visualization"), query, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
};

const stopPlayback = () => {
    if (playbackTimer) {
        clearInterval(playbackTimer);
        playbackTimer = null;
    }

    isPlaying.value = false;
};

const canGoNext = computed(() =>
    displayMode.value === "story"
        ? activeStoryIndex.value < storyChapters.value.length - 1
        : detailEventIndex.value < events.value.length - 1,
);

const canGoPrevious = computed(() =>
    displayMode.value === "story"
        ? activeStoryIndex.value > 0
        : detailEventIndex.value > 0,
);

const goPrevious = () => {
    stopPlayback();

    if (displayMode.value === "story") {
        activeStoryIndex.value = Math.max(activeStoryIndex.value - 1, 0);
        return;
    }

    detailEventIndex.value = Math.max(detailEventIndex.value - 1, 0);
};

const goNext = () => {
    stopPlayback();

    if (displayMode.value === "story") {
        activeStoryIndex.value = Math.min(activeStoryIndex.value + 1, storyChapters.value.length - 1);
        return;
    }

    detailEventIndex.value = Math.min(detailEventIndex.value + 1, events.value.length - 1);
};

const resetPlayback = () => {
    stopPlayback();
    activeStoryIndex.value = 0;
    detailEventIndex.value = 0;
};

const advancePlayback = () => {
    if (!canGoNext.value) {
        stopPlayback();
        return;
    }

    if (displayMode.value === "story") {
        activeStoryIndex.value += 1;
        return;
    }

    detailEventIndex.value += 1;
};

const startPlayback = () => {
    if (!canGoNext.value) return;

    stopPlayback();
    isPlaying.value = true;
    playbackTimer = setInterval(advancePlayback, playbackMs.value);
};

const togglePlayback = () => {
    if (isPlaying.value) {
        stopPlayback();
        return;
    }

    startPlayback();
};

const setStoryStep = (index) => {
    stopPlayback();
    displayMode.value = "story";
    activeStoryIndex.value = index;
};

const setRouteLearningMode = (mode) => {
    stopPlayback();
    routeLearningMode.value = mode;
};

const setImprovementLearningMode = (mode) => {
    stopPlayback();
    improvementLearningMode.value = mode;
};

const setTeachingTrip = (tripId) => {
    stopPlayback();
    selectedTeachingTripId.value = Number(tripId);
    nearestStepIndex.value = 0;
    twoOptMoveIndex.value = 0;
};

const stepRouteLearning = (delta) => {
    stopPlayback();

    if (effectiveRouteLearningMode.value === "two_opt") {
        twoOptMoveIndex.value = Math.min(
            Math.max(twoOptMoveIndex.value + delta, 0),
            Math.max(twoOptMoves.value.length - 1, 0),
        );
        return;
    }

    nearestStepIndex.value = Math.min(
        Math.max(nearestStepIndex.value + delta, 0),
        Math.max(nearestSteps.value.length - 1, 0),
    );
};

const destroyRouteMap = () => {
    if (routeLeafletMap) {
        routeLeafletMap.remove();
        routeLeafletMap = null;
        routeLeafletLayers = null;
    }

    routeMapCameraKey = null;
    routeMapUserAdjusted = false;
    routeMapProgrammaticCamera = false;
};

const makeRouteMarker = (label, type) =>
    L.divIcon({
        html: `<span aria-hidden="true">${label}</span>`,
        className: `leaflet-viz-marker is-${type}`,
        iconSize: [32, 32],
        iconAnchor: [16, 16],
    });

const runProgrammaticCameraChange = (callback) => {
    routeMapProgrammaticCamera = true;
    callback();
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            routeMapProgrammaticCamera = false;
        });
    });
};

const routeStepLatLngs = () => {
    if (effectiveRouteLearningMode.value === "nearest_neighbor" && activeNearestStep.value) {
        const points = [activeNearestStep.value.current_point];
        nearestCandidateRows.value.forEach((row) => {
            const student = studentById.value.get(Number(row.student_id));
            if (student) points.push(student);
        });

        return points
            .filter(Boolean)
            .map((point) => [Number(point.latitude), Number(point.longitude)]);
    }

    if (effectiveRouteLearningMode.value === "two_opt" && activeTwoOptMove.value) {
        return [
            ...(activeTwoOptMove.value.removed_edges ?? []),
            ...(activeTwoOptMove.value.added_edges ?? []),
        ]
            .flatMap((edge) => [edge.from, edge.to])
            .filter(Boolean)
            .map((point) => [Number(point.latitude), Number(point.longitude)]);
    }

    return routeMapPoints.value.map((point) => [Number(point.latitude), Number(point.longitude)]);
};

const refitRouteMap = (points, maxZoom) => {
    if (!routeLeafletMap || points.length === 0) return;

    runProgrammaticCameraChange(() => {
        routeLeafletMap.fitBounds(L.latLngBounds(points), {
            animate: false,
            maxZoom,
            padding: [52, 52],
        });
    });
    routeMapUserAdjusted = true;
    requestAnimationFrame(() => refreshRouteMap());
};

const focusRouteStep = () => refitRouteMap(routeStepLatLngs(), 17);
const showFullRoute = () => refitRouteMap(
    routeMapPoints.value.map((point) => [Number(point.latitude), Number(point.longitude)]),
    15,
);

const buildRouteMarkerPlacements = () => {
    if (!routeLeafletMap) return [];

    return clusterRouteStudents(routeMapStudentIds.value).flatMap((cluster, clusterIndex) => {
        if (cluster.students.length === 1) {
            const student = cluster.students[0];
            return [{
                student,
                clusterIndex,
                clusterSize: 1,
                isOffset: false,
                trueLatLng: [Number(student.latitude), Number(student.longitude)],
                displayLatLng: [Number(student.latitude), Number(student.longitude)],
            }];
        }

        const centerLatLng = L.latLng(Number(cluster.anchor.latitude), Number(cluster.anchor.longitude));
        const centerPoint = routeLeafletMap.latLngToLayerPoint(centerLatLng);
        const radius = 22 + Math.min(cluster.students.length, 6) * 2;

        return cluster.students.map((student, index) => {
            const angle = -Math.PI / 2 + (index * Math.PI * 2) / cluster.students.length;
            const displayPoint = L.point(
                centerPoint.x + Math.cos(angle) * radius,
                centerPoint.y + Math.sin(angle) * radius,
            );
            const displayLatLng = routeLeafletMap.layerPointToLatLng(displayPoint);

            return {
                student,
                clusterIndex,
                clusterSize: cluster.students.length,
                isOffset: true,
                trueLatLng: [Number(student.latitude), Number(student.longitude)],
                displayLatLng: [displayLatLng.lat, displayLatLng.lng],
            };
        });
    });
};

const refreshRouteMap = async () => {
    const isMapStage = ["route", "final"].includes(visualChapter.value?.id);

    if (!isMapStage) {
        destroyRouteMap();
        return;
    }

    await nextTick();

    if (!routeMapElement.value) return;

    if (!routeLeafletMap) {
        routeLeafletMap = L.map(routeMapElement.value, {
            attributionControl: true,
            zoomControl: true,
        });

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap",
            maxZoom: 19,
            opacity: 0.62,
        }).addTo(routeLeafletMap);

        routeLeafletLayers = L.layerGroup().addTo(routeLeafletMap);
        routeLeafletMap.on("dragstart zoomstart", () => {
            if (!routeMapProgrammaticCamera) {
                routeMapUserAdjusted = true;
            }
        });
    }

    routeLeafletLayers.clearLayers();

    const routeLatLngs = routeMapPoints.value.map((point) => [
        Number(point.latitude),
        Number(point.longitude),
    ]);
    const fitLatLngs = [...routeLatLngs];
    const cameraKey = displayMode.value === "detail"
        ? `detail:${currentEventIndex.value}:${focusTripId.value}:${effectiveRouteLearningMode.value}`
        : `story:${visualChapter.value?.id}:${focusTripId.value}:${effectiveRouteLearningMode.value}:${props.trace.direction}`;
    const shouldFitInitialView = routeMapCameraKey !== cameraKey;

    if (shouldFitInitialView) {
        routeMapCameraKey = cameraKey;
        routeMapUserAdjusted = false;
    }

    if (
        visualChapter.value?.id === "route" &&
        effectiveRouteLearningMode.value === "nearest_neighbor" &&
        activeNearestStep.value?.current_point
    ) {
        const current = activeNearestStep.value.current_point;
        const currentLatLng = [Number(current.latitude), Number(current.longitude)];

        nearestCandidateRows.value.forEach((row, index) => {
            const student = studentById.value.get(Number(row.student_id));
            if (!student) return;

            const candidateLatLng = [Number(student.latitude), Number(student.longitude)];
            fitLatLngs.push(candidateLatLng);

            L.polyline([currentLatLng, candidateLatLng], {
                className: row.selected ? "leaflet-candidate-selected" : "leaflet-candidate-option",
                color: row.selected ? "var(--color-accent)" : "var(--color-muted)",
                dashArray: row.selected ? null : "5 7",
                lineCap: "round",
                opacity: row.selected ? 1 : 0.58,
                weight: row.selected ? 6 : 3,
            })
                .bindTooltip(`${index + 1}. ${student.name} · ${formatKm(row.distance_km)}`)
                .addTo(routeLeafletLayers);

            if (!row.selected) {
                L.marker(candidateLatLng, {
                    icon: makeRouteMarker(index + 1, "candidate"),
                    keyboard: false,
                })
                    .bindTooltip(`${student.name} · ${formatKm(row.distance_km)}`)
                    .addTo(routeLeafletLayers);
            }
        });

        fitLatLngs.push(currentLatLng);
        L.circleMarker(currentLatLng, {
            className: "leaflet-current-point",
            color: "var(--color-warning)",
            fillColor: "var(--color-warning-soft)",
            fillOpacity: 0.22,
            opacity: 0.95,
            radius: 16,
            weight: 4,
        })
            .bindTooltip(`Posisi saat ini: ${current.label}`)
            .addTo(routeLeafletLayers);
    }

    if (routeLatLngs.length > 1) {
        L.polyline(routeLatLngs, {
            className: "leaflet-route-casing",
            color: "var(--color-panel)",
            lineCap: "round",
            lineJoin: "round",
            opacity: 0.96,
            weight: 10,
        }).addTo(routeLeafletLayers);

        L.polyline(routeLatLngs, {
            className: "leaflet-route-line",
            color: effectiveRouteLearningMode.value === "two_opt" && activeTwoOptMove.value
                ? "var(--color-muted)"
                : "var(--color-accent)",
            dashArray: effectiveRouteLearningMode.value === "two_opt" && activeTwoOptMove.value
                ? "7 7"
                : null,
            lineCap: "round",
            lineJoin: "round",
            opacity: effectiveRouteLearningMode.value === "two_opt" && activeTwoOptMove.value ? 0.72 : 0.96,
            weight: effectiveRouteLearningMode.value === "two_opt" && activeTwoOptMove.value ? 4 : 5,
        }).addTo(routeLeafletLayers);
    }

    if (
        visualChapter.value?.id === "route" &&
        effectiveRouteLearningMode.value === "two_opt" &&
        activeTwoOptMove.value
    ) {
        const drawChangedEdge = (edge, changeType) => {
            const latLngs = [edge.from, edge.to].map((point) => [
                Number(point.latitude),
                Number(point.longitude),
            ]);
            fitLatLngs.push(...latLngs);

            L.polyline(latLngs, {
                className: `leaflet-edge-${changeType}`,
                color: changeType === "removed" ? "var(--color-danger)" : "var(--color-success)",
                dashArray: changeType === "removed" ? "4 7" : null,
                lineCap: "round",
                opacity: 1,
                weight: 7,
            })
                .bindTooltip(
                    `${changeType === "removed" ? "Dilepas" : "Ditambah"}: ${edge.from.label} → ${edge.to.label}`,
                )
                .addTo(routeLeafletLayers);
        };

        activeTwoOptMove.value.removed_edges?.forEach((edge) => drawChangedEdge(edge, "removed"));
        activeTwoOptMove.value.added_edges?.forEach((edge) => drawChangedEdge(edge, "added"));
    }

    if (shouldFitInitialView && fitLatLngs.length > 0) {
        runProgrammaticCameraChange(() => {
            routeLeafletMap.fitBounds(L.latLngBounds(fitLatLngs), {
                animate: false,
                maxZoom: 15,
                padding: [42, 42],
            });
        });
    } else if (shouldFitInitialView && school.value) {
        runProgrammaticCameraChange(() => {
            routeLeafletMap.setView([Number(school.value.latitude), Number(school.value.longitude)], 13, {
                animate: false,
            });
        });
    } else if (!routeMapUserAdjusted) {
        const stepLatLngs = routeStepLatLngs();
        if (stepLatLngs.length > 0) {
            runProgrammaticCameraChange(() => {
                routeLeafletMap.panInsideBounds(L.latLngBounds(stepLatLngs), {
                    animate: false,
                    paddingTopLeft: [64, 64],
                    paddingBottomRight: [64, 64],
                });
            });
        }
    }

    if (props.trace.direction === "morning" && focusTrip.value) {
        L.marker(
            [Number(focusTrip.value.fleet.base_latitude), Number(focusTrip.value.fleet.base_longitude)],
            { icon: makeRouteMarker("P", "pool"), keyboard: false },
        ).addTo(routeLeafletLayers);
    }

    const markerOrder = new Map(routeMapStudentIds.value.map((studentId, index) => [Number(studentId), index + 1]));
    const markerPlacements = buildRouteMarkerPlacements();
    const renderedClusterAnchors = new Set();

    markerPlacements.forEach((placement) => {
        const student = placement.student;
        const order = markerOrder.get(Number(student.id));

        if (placement.isOffset) {
            L.polyline([placement.trueLatLng, placement.displayLatLng], {
                className: "leaflet-marker-leader",
                color: "var(--color-ink-soft)",
                dashArray: "2 4",
                interactive: false,
                opacity: 0.62,
                weight: 1.5,
            }).addTo(routeLeafletLayers);

            if (!renderedClusterAnchors.has(placement.clusterIndex)) {
                renderedClusterAnchors.add(placement.clusterIndex);
                L.circleMarker(placement.trueLatLng, {
                    className: "leaflet-cluster-origin",
                    color: "var(--color-panel)",
                    fillColor: "var(--color-ink-soft)",
                    fillOpacity: 0.88,
                    interactive: false,
                    radius: 4,
                    weight: 2,
                }).addTo(routeLeafletLayers);
            }
        }

        L.marker(placement.displayLatLng, {
            icon: makeRouteMarker(
                order,
                visualChapter.value?.id === "route" &&
                    effectiveRouteLearningMode.value === "two_opt" &&
                    twoOptSegmentBeforeStudentIds.value.has(Number(student.id))
                    ? "changed"
                    : "student",
            ),
            keyboard: false,
        })
            .bindTooltip(
                `Posisi ${order} · ${student.name}${placement.isOffset ? " · marker digeser dari lokasi asli agar terbaca" : ""}`,
            )
            .addTo(routeLeafletLayers);
    });

    if (school.value) {
        L.marker([Number(school.value.latitude), Number(school.value.longitude)], {
            icon: makeRouteMarker("S", "school"),
            keyboard: false,
        }).addTo(routeLeafletLayers);
    }

    requestAnimationFrame(() => routeLeafletMap?.invalidateSize({ animate: false }));
};

watch(displayMode, () => {
    stopPlayback();
});

watch(
    defaultTeachingTripId,
    (tripId) => {
        const selectedStillExists = routeTripOptions.value.some(
            (option) => Number(option.id) === Number(selectedTeachingTripId.value),
        );

        if (!selectedStillExists) {
            selectedTeachingTripId.value = tripId;
        }
    },
    { immediate: true },
);

watch(
    () => [
        visualChapter.value?.id,
        currentEventIndex.value,
        focusTripId.value,
        effectiveRouteLearningMode.value,
        activeNearestStepIndex.value,
        activeTwoOptMoveIndex.value,
    ],
    () => refreshRouteMap(),
    { flush: "post" },
);

watch(playbackMs, () => {
    if (isPlaying.value) {
        startPlayback();
    }
});

watch(
    () => [props.direction, props.session, props.trace?.events?.length],
    () => {
        selectedDirection.value = props.direction ?? "morning";
        selectedSession.value = props.session ?? props.availableSessions[0] ?? "";
        activeStoryIndex.value = 0;
        detailEventIndex.value = 0;
        routeLearningMode.value = "nearest_neighbor";
        improvementLearningMode.value = "local_move";
        selectedTeachingTripId.value = null;
        nearestStepIndex.value = 0;
        twoOptMoveIndex.value = 0;
        stopPlayback();
    },
);

onBeforeUnmount(() => {
    stopPlayback();
    destroyRouteMap();
});
</script>

<template>
    <Head title="Visualisasi Algoritma Rute" />

    <AuthenticatedLayout>
        <template #header>
            <div class="route-viz-header" :class="{ 'is-focus-stage': visualChapter?.id === 'route' }">
                <div v-if="visualChapter?.id !== 'route'">
                    <p class="route-viz-kicker">Mode skripsi</p>
                    <h2>Visualisasi algoritma rute</h2>
                </div>

                <div v-else class="focus-page-title">
                    <span>Tahap 5 dari 6</span>
                    <strong>Visualisasi urutan rute</strong>
                </div>

                <Link :href="route('admin.dashboard')" class="route-viz-link">
                    Monitoring Armada
                </Link>
            </div>
        </template>

        <main class="route-viz" :class="{ 'is-focus-stage': visualChapter?.id === 'route' }">
            <section class="lesson-toolbar" :class="{ 'is-focus-stage': visualChapter?.id === 'route' }" aria-label="Kontrol visualisasi">
                <div class="lesson-mode" role="group" aria-label="Mode tampilan">
                    <button
                        type="button"
                        :aria-pressed="displayMode === 'story'"
                        @click="displayMode = 'story'"
                    >
                        Penjelasan
                    </button>
                    <button
                        type="button"
                        :aria-pressed="displayMode === 'detail'"
                        @click="displayMode = 'detail'"
                    >
                        Data teknis
                    </button>
                </div>

                <p v-if="visualChapter?.id !== 'route'" class="lesson-summary">
                    <strong>{{ summary.eligible_students ?? 0 }} siswa</strong>
                    <span aria-hidden="true">→</span>
                    <strong>{{ summary.active_trips ?? 0 }} rit</strong>
                    <span aria-hidden="true">→</span>
                    <strong>6 tahap</strong>
                </p>

                <div class="lesson-filters">
                    <label>
                        <span>Arah rute</span>
                        <select v-model="selectedDirection" @change="applyFilters">
                            <option value="morning">Pagi · rumah ke sekolah</option>
                            <option value="afternoon">Pulang · sekolah ke rumah</option>
                        </select>
                    </label>
                    <label v-if="selectedDirection === 'afternoon'">
                        <span>Sesi pulang</span>
                        <select v-model="selectedSession" @change="applyFilters">
                            <option
                                v-for="sessionOption in availableSessions"
                                :key="sessionOption"
                                :value="sessionOption"
                            >
                                {{ formatTime(sessionOption) }} WIB
                            </option>
                        </select>
                    </label>
                </div>
            </section>

            <section class="lesson-shell" :class="{ 'is-map-stage': visualChapter?.id === 'route' }">
                <nav class="stage-rail" aria-label="Tahapan algoritma">
                    <p>Alur algoritma</p>
                    <button
                        v-for="(chapter, index) in storyChapters"
                        :key="chapter.id"
                        type="button"
                        :aria-current="displayMode === 'story' && activeStoryIndex === index ? 'step' : undefined"
                        @click="setStoryStep(index)"
                    >
                        <span>{{ String(index + 1).padStart(2, "0") }}</span>
                        <strong>{{ chapter.short }}</strong>
                    </button>
                </nav>

                <article class="lesson-stage" aria-live="polite">
                    <header class="lesson-stage-head">
                        <div class="stage-number">{{ String(visualChapterIndex + 1).padStart(2, "0") }}</div>
                        <div>
                            <p>{{ visualChapter?.short }}</p>
                            <h3>{{ lessonStageTitle }}</h3>
                            <span>{{ lessonStageIntent }}</span>
                        </div>
                    </header>

                    <div class="teaching-canvas">
                        <div v-if="visualChapter?.id === 'filter'" class="filter-visual">
                            <div class="input-pile">
                                <span v-for="index in 12" :key="index" aria-hidden="true"></span>
                                <strong>Data siswa & armada</strong>
                            </div>
                            <div class="filter-gates" aria-label="Syarat seleksi">
                                <div><span>01</span><strong>Pembayaran lunas</strong></div>
                                <div><span>02</span><strong>Layanan sesuai arah</strong></div>
                                <div><span>03</span><strong>Koordinat valid</strong></div>
                                <div><span>04</span><strong>Rit masih aktif</strong></div>
                            </div>
                            <div class="filter-result">
                                <span>Lolos seleksi</span>
                                <strong>{{ summary.eligible_students ?? 0 }}</strong>
                                <small>siswa siap dialokasikan</small>
                            </div>
                        </div>

                        <div v-else-if="visualChapter?.id === 'priority'" class="priority-visual">
                            <div class="priority-head">
                                <div>
                                    <span>Antrean awal</span>
                                    <strong>Jarak ke sekolah</strong>
                                </div>
                                <p>Terjauh diproses pertama</p>
                            </div>
                            <ol class="priority-list">
                                <li v-for="student in priorityStudents" :key="student.id">
                                    <b>{{ student.rank }}</b>
                                    <div>
                                        <span>{{ student.name }}</span>
                                        <i :style="{ width: priorityBarWidth(student) }"></i>
                                    </div>
                                    <strong>{{ Number(student.school_distance_km).toFixed(2) }} km</strong>
                                </li>
                            </ol>
                            <div class="decision-line">
                                <span>Kenapa?</span>
                                Titik yang sulit ditempatkan diamankan lebih dulu agar tidak tersisa di akhir.
                            </div>
                        </div>

                        <div v-else-if="visualChapter?.id === 'score'" class="score-visual">
                            <div class="candidate-card">
                                <span>Siswa kandidat</span>
                                <strong>{{ activeStudent?.name ?? "-" }}</strong>
                                <small>{{ formatKm(activeStudent?.school_distance_km) }} dari sekolah</small>
                            </div>
                            <div class="choice-arrow" aria-hidden="true"><span>dibandingkan ke</span>→</div>
                            <div class="score-choices">
                                <div
                                    v-for="row in simplifiedScoreRows"
                                    :key="row.trip_id"
                                    class="score-choice"
                                    :class="{ 'is-winner': row.selected }"
                                >
                                    <div class="score-choice-head">
                                        <span>{{ row.selected ? "Skor terkecil · dipilih" : "Alternatif" }}</span>
                                        <strong>{{ Number(row.score).toFixed(3) }}</strong>
                                    </div>
                                    <b>{{ shortTripLabel(row.trip_label) }}</b>
                                    <div class="score-breakdown">
                                        <span>
                                            <i :style="{ width: scoreComponentWidth(row.insertion_cost_km, row) }"></i>
                                            Sisip {{ Number(row.insertion_cost_km ?? 0).toFixed(3) }}
                                        </span>
                                        <span>
                                            <i :style="{ width: scoreComponentWidth(row.capacity_penalty, row) }"></i>
                                            Kapasitas {{ Number(row.capacity_penalty ?? 0).toFixed(3) }}
                                        </span>
                                        <span>
                                            <i :style="{ width: scoreComponentWidth(row.outlier_penalty, row) }"></i>
                                            Outlier {{ Number(row.outlier_penalty ?? 0).toFixed(3) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="visualChapter?.id === 'improve'" class="improve-visual">
                            <div v-if="displayMode === 'story'" class="improve-method-tabs" role="tablist" aria-label="Metode perapian alokasi">
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeImprovementPhase === 'local_move'"
                                    :class="{ 'is-active': activeImprovementPhase === 'local_move' }"
                                    @click="setImprovementLearningMode('local_move')"
                                >
                                    <span>4A</span> Local Move
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeImprovementPhase === 'rebalance'"
                                    :class="{ 'is-active': activeImprovementPhase === 'rebalance' }"
                                    @click="setImprovementLearningMode('rebalance')"
                                >
                                    <span>4B</span> Soft Rebalance
                                </button>
                            </div>

                            <div class="improve-rules" :class="{ 'is-tradeoff': activeImprovementPhase === 'rebalance' }">
                                <template v-if="activeImprovementPhase === 'local_move'">
                                    <span><b>1</b>Tujuan masih muat</span>
                                    <span><b>2</b>Hitung ulang dua rit</span>
                                    <span><b>3</b>Terima jika jarak turun</span>
                                </template>
                                <template v-else>
                                    <span><b>1</b>Sumber &gt; {{ Number(activeImprovementEvent?.overfilled_ratio ?? 0.9) * 100 }}%</span>
                                    <span><b>2</b>Tujuan &lt; {{ Number(activeImprovementEvent?.underfilled_ratio ?? 0.65) * 100 }}%</span>
                                    <span><b>3</b>Sumber sesudah ≥ {{ Number(activeImprovementEvent?.underfilled_ratio ?? 0.65) * 100 }}%</span>
                                    <span><b>4</b>Tambahan ≤ {{ formatKm(activeImprovementEvent?.max_extra_distance_km ?? (props.trace.direction === 'morning' ? 1.5 : 2)) }}</span>
                                </template>
                            </div>

                            <div class="before-after-labels"><span>Sebelum</span><span>Sesudah</span></div>
                            <div
                                v-for="item in improvementTrips"
                                :key="item.tripId"
                                class="load-comparison"
                            >
                                <div class="load-name">
                                    <span>{{ item.role }}</span>
                                    <strong>{{ shortTripLabel(item.trip?.label) }}</strong>
                                </div>
                                <div class="load-box">
                                    <b>{{ item.beforeLoad }}/{{ item.capacity }}</b>
                                    <span><i :style="{ width: `${Math.min((item.beforeLoad / item.capacity) * 100, 100)}%` }"></i></span>
                                </div>
                                <div class="move-arrow" aria-hidden="true">→</div>
                                <div class="load-box is-after">
                                    <b>{{ item.afterLoad }}/{{ item.capacity }}</b>
                                    <span><i :style="{ width: `${Math.min((item.afterLoad / item.capacity) * 100, 100)}%` }"></i></span>
                                </div>
                            </div>
                            <div
                                v-if="improvementTrips.length"
                                class="move-result"
                                :class="{ 'is-tradeoff': activeImprovementPhase === 'rebalance' }"
                            >
                                <span>{{ activeStudent?.name ?? "Satu siswa" }} dipindahkan</span>
                                <strong>
                                    {{ activeImprovementPhase === "rebalance"
                                        ? `${formatSignedKm(activeImprovementEvent?.extra_distance_km ?? 0)} jarak · beban lebih seimbang`
                                        : `${formatKm(Math.abs(Number(activeImprovementEvent?.distance_delta_km ?? 0)))} lebih pendek` }}
                                </strong>
                            </div>
                            <div v-else class="no-change">
                                {{ activeImprovementPhase === "rebalance"
                                    ? "Tidak ada pasangan rit yang memenuhi seluruh batas soft rebalance. Alokasi dipertahankan."
                                    : "Tidak ada perpindahan siswa yang memendekkan jarak gabungan dua rit. Alokasi dipertahankan." }}
                            </div>
                        </div>

                        <div v-else class="route-visual">
                            <div v-if="visualChapter?.id === 'route' && displayMode === 'story'" class="route-learning-toolbar">
                                <div class="focus-route-heading">
                                    <span>Urutan rute</span>
                                    <strong>{{ effectiveRouteLearningMode === "two_opt" ? "Perbaiki dengan 2-opt" : "Pilih titik terdekat" }}</strong>
                                </div>
                                <div class="route-method-tabs" role="tablist" aria-label="Metode penyusunan urutan">
                                    <button
                                        type="button"
                                        role="tab"
                                        :aria-selected="effectiveRouteLearningMode === 'nearest_neighbor'"
                                        :class="{ 'is-active': effectiveRouteLearningMode === 'nearest_neighbor' }"
                                        @click="setRouteLearningMode('nearest_neighbor')"
                                    >
                                        <span>5A</span> Nearest Neighbor
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        :aria-selected="effectiveRouteLearningMode === 'two_opt'"
                                        :class="{ 'is-active': effectiveRouteLearningMode === 'two_opt' }"
                                        @click="setRouteLearningMode('two_opt')"
                                    >
                                        <span>5B</span> 2-opt
                                    </button>
                                </div>
                                <label class="teaching-trip-control">
                                    <span>Rit contoh</span>
                                    <select :value="focusTripId" @change="setTeachingTrip($event.target.value)">
                                        <option
                                            v-for="option in routeTripOptions"
                                            :key="option.id"
                                            :value="option.id"
                                        >
                                            {{ shortTripLabel(option.trip.label) }} · {{ option.studentCount }} titik{{ option.id === defaultTeachingTripId ? " · direkomendasikan" : "" }}
                                        </option>
                                    </select>
                                </label>
                                <div class="route-micro-stepper">
                                    <button
                                        type="button"
                                        aria-label="Langkah sebelumnya"
                                        :disabled="effectiveRouteLearningMode === 'two_opt'
                                            ? activeTwoOptMoveIndex === 0
                                            : activeNearestStepIndex === 0"
                                        @click="stepRouteLearning(-1)"
                                    ><span aria-hidden="true">←</span> Sebelumnya</button>
                                    <strong>{{ routeMicroProgress }}</strong>
                                    <button
                                        type="button"
                                        class="is-primary"
                                        aria-label="Langkah berikutnya"
                                        :disabled="effectiveRouteLearningMode === 'two_opt'
                                            ? activeTwoOptMoveIndex >= twoOptMoves.length - 1
                                            : activeNearestStepIndex >= nearestSteps.length - 1"
                                        @click="stepRouteLearning(1)"
                                    >Berikutnya <span aria-hidden="true">→</span></button>
                                </div>
                            </div>

                            <div class="route-map">
                                <div
                                    ref="routeMapElement"
                                    class="route-map-canvas"
                                    role="region"
                                    :aria-label="visualChapter?.id === 'final'
                                        ? 'Peta geografis rute akhir dan urutan siswa'
                                        : effectiveRouteLearningMode === 'two_opt'
                                            ? 'Peta perubahan sisi rute pada langkah 2-opt'
                                            : 'Peta perbandingan kandidat nearest neighbor'"
                                ></div>

                                <aside v-if="visualChapter?.id === 'route'" class="route-step-inspector" aria-label="Penjelasan langkah aktif">
                                    <div class="route-step-inspector-head">
                                        <span>{{ effectiveRouteLearningMode === "two_opt" ? "2-opt" : "Nearest Neighbor" }}</span>
                                        <strong>{{ routeMicroProgress }}</strong>
                                    </div>

                                    <template v-if="effectiveRouteLearningMode === 'nearest_neighbor' && activeNearestStep">
                                        <p class="inspector-decision">
                                            Dari <strong>{{ activeNearestStep.current_point?.label }}</strong>, pilih titik terdekat.
                                        </p>
                                        <ol class="inspector-candidates" aria-label="Kandidat titik terdekat">
                                            <li
                                                v-for="(row, index) in nearestCandidateRows"
                                                :key="row.student_id"
                                                :class="{ 'is-selected': row.selected }"
                                            >
                                                <b>{{ index + 1 }}</b>
                                                <span>{{ row.student_name }}</span>
                                                <strong>{{ formatKm(row.distance_km) }}</strong>
                                            </li>
                                        </ol>
                                        <small>{{ activeNearestStep.unvisited_before }} siswa belum dikunjungi.</small>
                                    </template>

                                    <template v-else-if="activeTwoOptMove">
                                        <p class="inspector-decision">
                                            Balik posisi <strong>{{ activeTwoOptMove.start_position }}–{{ activeTwoOptMove.end_position }}</strong>
                                        </p>
                                        <div class="inspector-distance">
                                            <span>{{ formatKm(activeTwoOptMove.distance_before_km) }}</span>
                                            <b aria-hidden="true">→</b>
                                            <strong>{{ formatKm(activeTwoOptMove.distance_after_km) }}</strong>
                                        </div>
                                        <p class="inspector-saving">
                                            Hemat {{ formatDistanceChange(activeTwoOptMove.distance_delta_km) }}
                                            <span>· {{ twoOptSavingPercent > 0 && twoOptSavingPercent < 0.1 ? "<0.1" : twoOptSavingPercent.toFixed(1) }}%</span>
                                        </p>
                                        <p class="inspector-segment">
                                            <b>{{ activeTwoOptMove.start_position }}</b>{{ twoOptStartStudent?.name ?? "Titik awal" }}
                                            <span aria-hidden="true">↔</span>
                                            <b>{{ activeTwoOptMove.end_position }}</b>{{ twoOptEndStudent?.name ?? "Titik akhir" }}
                                        </p>
                                        <details class="inspector-details">
                                            <summary>Lihat sisi yang berubah</summary>
                                            <p><b class="is-removed">−</b> {{ edgeSummary(activeTwoOptMove.removed_edges) }}</p>
                                            <p><b class="is-added">+</b> {{ edgeSummary(activeTwoOptMove.added_edges) }}</p>
                                        </details>
                                    </template>

                                    <template v-else>
                                        <p class="inspector-decision">Urutan NN sudah optimal secara lokal.</p>
                                        <small>{{ twoOptEvent?.evaluated_candidates ?? 0 }} kandidat diperiksa; tidak ada pembalikan yang lebih pendek.</small>
                                    </template>
                                </aside>

                                <div v-if="visualChapter?.id === 'route'" class="map-camera-actions" aria-label="Kontrol fokus peta">
                                    <button type="button" @click="focusRouteStep">Fokus langkah</button>
                                    <button type="button" @click="showFullRoute">Lihat semua rute</button>
                                </div>

                                <div class="map-key" aria-label="Legenda peta">
                                    <span v-if="props.trace.direction === 'morning'"><i class="is-pool">P</i>Pool</span>
                                    <span><i class="is-student">1</i>Urutan siswa</span>
                                    <span><i class="is-school">S</i>Sekolah</span>
                                    <template v-if="visualChapter?.id === 'route' && effectiveRouteLearningMode === 'nearest_neighbor'">
                                        <span><i class="line-sample is-choice"></i>Pilihan terdekat</span>
                                        <span><i class="line-sample is-candidate"></i>Kandidat lain</span>
                                    </template>
                                    <template v-else-if="visualChapter?.id === 'route' && activeTwoOptMove">
                                        <span><i class="line-sample is-before"></i>Urutan sebelum</span>
                                        <span><i class="line-sample is-removed"></i>Sisi dilepas</span>
                                        <span><i class="line-sample is-added"></i>Sisi ditambah</span>
                                        <small>Merah putus-putus = sebelum · hijau penuh = pengganti</small>
                                    </template>
                                    <small v-else-if="visualChapter?.id === 'route'">Garis biru = urutan NN; tidak ada pembalikan yang lebih pendek</small>
                                    <small v-else>Garis biru penuh = rute final</small>
                                </div>

                                <p v-if="overlappingRouteGroupCount > 0" class="map-coordinate-note">
                                    {{ overlappingRouteGroupCount }} kelompok marker berdekatan digeser agar nomor terbaca. Garis rute dan perhitungan tetap memakai koordinat asli.
                                </p>
                            </div>

                            <div v-if="visualChapter?.id === 'route' && effectiveRouteLearningMode === 'two_opt' && activeTwoOptMove" class="sequence-comparison">
                                <div>
                                    <strong>Sebelum</strong>
                                    <div class="route-sequence">
                                        <span v-if="twoOptBeforeContext.hiddenBefore" class="sequence-ellipsis">… {{ twoOptBeforeContext.hiddenBefore }} titik</span>
                                        <span v-else class="sequence-start">{{ props.trace.direction === "morning" ? "Pool" : "Sekolah" }}</span>
                                        <template v-for="student in twoOptBeforeContext.students" :key="`before-${student.id}`">
                                            <i aria-hidden="true">→</i>
                                            <span :class="{ 'is-changed': activeTwoOptMove.segment_before_ids?.map(Number).includes(Number(student.id)) }"><b>{{ student.order }}</b>{{ student.name }}</span>
                                        </template>
                                        <template v-if="twoOptBeforeContext.hiddenAfter">
                                            <i aria-hidden="true">→</i><span class="sequence-ellipsis">{{ twoOptBeforeContext.hiddenAfter }} titik …</span>
                                        </template>
                                        <template v-else-if="props.trace.direction === 'morning'">
                                            <i aria-hidden="true">→</i><span class="sequence-end">Sekolah</span>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <strong>Sesudah</strong>
                                    <div class="route-sequence is-after">
                                        <span v-if="twoOptAfterContext.hiddenBefore" class="sequence-ellipsis">… {{ twoOptAfterContext.hiddenBefore }} titik</span>
                                        <span v-else class="sequence-start">{{ props.trace.direction === "morning" ? "Pool" : "Sekolah" }}</span>
                                        <template v-for="student in twoOptAfterContext.students" :key="`after-${student.id}`">
                                            <i aria-hidden="true">→</i>
                                            <span :class="{ 'is-changed': twoOptSegmentStudentIds.has(Number(student.id)) }"><b>{{ student.order }}</b>{{ student.name }}</span>
                                        </template>
                                        <template v-if="twoOptAfterContext.hiddenAfter">
                                            <i aria-hidden="true">→</i><span class="sequence-ellipsis">{{ twoOptAfterContext.hiddenAfter }} titik …</span>
                                        </template>
                                        <template v-else-if="props.trace.direction === 'morning'">
                                            <i aria-hidden="true">→</i><span class="sequence-end">Sekolah</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="route-sequence">
                                <span class="sequence-start">{{ props.trace.direction === "morning" ? "Pool" : "Sekolah" }}</span>
                                <template v-for="student in visibleRouteStudents" :key="student.id">
                                    <i aria-hidden="true">→</i>
                                    <span><b>{{ student.order }}</b>{{ student.name }}</span>
                                </template>
                                <template v-if="props.trace.direction === 'morning' && (visualChapter?.id === 'final' || effectiveRouteLearningMode === 'two_opt' || activeNearestStepIndex === nearestSteps.length - 1)">
                                    <i aria-hidden="true">→</i>
                                    <span class="sequence-end">Sekolah</span>
                                </template>
                            </div>

                            <div v-if="visualChapter?.id === 'route'" class="focus-stage-footer">
                                <span>{{ decisionTitle }}</span>
                                <div class="lesson-actions route-stage-actions">
                                    <button type="button" :disabled="!canGoPrevious" @click="goPrevious">Sebelumnya</button>
                                    <button type="button" :disabled="!canGoNext && !isPlaying" @click="togglePlayback">{{ isPlaying ? "Jeda" : "Putar" }}</button>
                                    <button type="button" class="is-primary" :disabled="!canGoNext" @click="goNext">Berikutnya</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="displayMode === 'detail'" class="technical-drawer">
                        <label for="detail-event-range">Event {{ detailEventIndex + 1 }} dari {{ events.length }}</label>
                        <input id="detail-event-range" v-model.number="detailEventIndex" type="range" min="0" :max="Math.max(events.length - 1, 0)" @input="stopPlayback" />
                        <p><strong>{{ currentEvent?.phase }}</strong> · {{ currentEvent?.description }}</p>
                    </div>
                </article>

                <aside v-if="visualChapter?.id !== 'route'" class="decision-panel">
                    <p class="decision-kicker">Keputusan tahap {{ visualChapterIndex + 1 }}</p>
                    <h3>{{ decisionTitle }}</h3>

                    <dl>
                        <div v-for="fact in chapterFacts" :key="fact.label">
                            <dt>{{ fact.label }}</dt>
                            <dd>{{ fact.value }}</dd>
                        </div>
                    </dl>

                    <div v-if="visualChapter?.id === 'score'" class="formula-line">
                        <span>Sisip</span><b>+</b><span>Kapasitas</span><b>+</b><span>Outlier</span><b>=</b><strong>Skor</strong>
                    </div>

                    <div v-if="displayMode === 'detail' && currentEvent?.score_rows?.length" class="technical-score-list">
                        <div v-for="row in currentEvent.score_rows" :key="row.trip_id" :class="{ 'is-selected': row.selected }">
                            <span>{{ shortTripLabel(row.trip_label) }}</span>
                            <strong>{{ row.score?.toFixed(3) ?? "-" }}</strong>
                        </div>
                    </div>

                    <div class="lesson-actions">
                        <button type="button" :disabled="!canGoPrevious" @click="goPrevious">Kembali</button>
                        <button type="button" class="is-primary" :disabled="!canGoNext && !isPlaying" @click="togglePlayback">{{ isPlaying ? "Jeda" : "Putar" }}</button>
                        <button type="button" :disabled="!canGoNext" @click="goNext">Lanjut</button>
                    </div>
                    <p class="progress-label">{{ progressText }} · {{ displayMode === "story" ? "tahap" : "event" }}</p>
                </aside>
            </section>

            <template v-if="false">
            <section class="route-viz-toolbar" aria-label="Kontrol visualisasi">
                <div class="route-viz-mode" role="group" aria-label="Mode tampilan">
                    <button
                        type="button"
                        :aria-pressed="displayMode === 'story'"
                        @click="displayMode = 'story'"
                    >
                        Mode Skripsi
                    </button>
                    <button
                        type="button"
                        :aria-pressed="displayMode === 'detail'"
                        @click="displayMode = 'detail'"
                    >
                        Detail Teknis
                    </button>
                </div>

                <div class="route-viz-filters">
                    <label>
                        <span>Rute</span>
                        <select v-model="selectedDirection" @change="applyFilters">
                            <option value="morning">Rute Pagi</option>
                            <option value="afternoon">Rute Pulang</option>
                        </select>
                    </label>

                    <label v-if="selectedDirection === 'afternoon'">
                        <span>Sesi</span>
                        <select v-model="selectedSession" @change="applyFilters">
                            <option
                                v-for="sessionOption in availableSessions"
                                :key="sessionOption"
                                :value="sessionOption"
                            >
                                {{ formatTime(sessionOption) }} WIB
                            </option>
                        </select>
                    </label>

                    <label>
                        <span>Tempo</span>
                        <select v-model.number="playbackMs">
                            <option :value="2200">Pelan</option>
                            <option :value="1500">Normal</option>
                            <option :value="900">Cepat</option>
                        </select>
                    </label>
                </div>

                <div class="route-viz-actions">
                    <button type="button" class="button-secondary" @click="resetPlayback">
                        Reset
                    </button>
                    <button
                        type="button"
                        class="button-secondary"
                        :disabled="!canGoPrevious"
                        @click="goPrevious"
                    >
                        Prev
                    </button>
                    <button
                        type="button"
                        class="button-primary"
                        :disabled="!canGoNext && !isPlaying"
                        @click="togglePlayback"
                    >
                        {{ isPlaying ? "Pause" : "Play" }}
                    </button>
                    <button
                        type="button"
                        class="button-secondary"
                        :disabled="!canGoNext"
                        @click="goNext"
                    >
                        Next
                    </button>
                </div>
            </section>

            <section class="route-viz-overview" aria-label="Ringkasan data trace">
                <div>
                    <span>{{ summary.eligible_students ?? 0 }}</span>
                    <p>Siswa eligible</p>
                </div>
                <div>
                    <span>{{ summary.active_trips ?? 0 }}</span>
                    <p>Rit aktif</p>
                </div>
                <div>
                    <span>{{ events.length }}</span>
                    <p>Event trace asli</p>
                </div>
                <div>
                    <span>{{ progressText }}</span>
                    <p>Posisi playback</p>
                </div>
            </section>

            <section class="route-viz-workbench">
                <article class="map-panel" aria-live="polite">
                    <div class="chapter-strip" v-if="displayMode === 'story'">
                        <button
                            v-for="(chapter, index) in storyChapters"
                            :key="chapter.id"
                            type="button"
                            :aria-pressed="activeStoryIndex === index"
                            @click="setStoryStep(index)"
                        >
                            <span>{{ String(index + 1).padStart(2, "0") }}</span>
                            {{ chapter.short }}
                        </button>
                    </div>

                    <div class="map-panel-head">
                        <div>
                            <p class="route-viz-kicker">
                                {{ displayMode === "story" ? activeChapter?.short : currentEvent?.phase }}
                            </p>
                            <h3>
                                {{ displayMode === "story" ? activeChapter?.title : currentEvent?.title }}
                            </h3>
                            <p>
                                {{ displayMode === "story" ? activeChapter?.intent : currentEvent?.description }}
                            </p>
                        </div>

                        <div class="map-focus">
                            <span>Fokus</span>
                            <strong>{{ focusTrip ? shortTripLabel(focusTrip.label) : "Belum ada rit" }}</strong>
                        </div>
                    </div>

                    <div class="map-canvas">
                        <svg
                            viewBox="0 0 1000 560"
                            role="img"
                            aria-label="Peta skematik proses optimasi rute"
                        >
                            <rect class="map-canvas-bg" x="0" y="0" width="1000" height="560" />

                            <polyline
                                v-if="focusRoutePoints.length > 1"
                                :points="pointString(focusRoutePoints)"
                                fill="none"
                                :stroke="focusTrip ? tripColor(focusTrip.id) : palette[0]"
                                class="route-line"
                                :class="{ 'is-final': currentEvent?.phase === 'road_geometry' }"
                            />

                            <g v-if="school">
                                <circle
                                    class="school-marker"
                                    :cx="projectPoint(school.latitude, school.longitude).x"
                                    :cy="projectPoint(school.latitude, school.longitude).y"
                                    r="15"
                                />
                                <text
                                    class="school-label"
                                    :x="projectPoint(school.latitude, school.longitude).x + 20"
                                    :y="projectPoint(school.latitude, school.longitude).y + 5"
                                >
                                    Sekolah
                                </text>
                            </g>

                            <g v-if="focusTrip">
                                <rect
                                    class="fleet-marker"
                                    :x="projectPoint(focusTrip.fleet.base_latitude, focusTrip.fleet.base_longitude).x - 11"
                                    :y="projectPoint(focusTrip.fleet.base_latitude, focusTrip.fleet.base_longitude).y - 11"
                                    width="22"
                                    height="22"
                                    rx="5"
                                    :style="{ '--trip-color': tripColor(focusTrip.id) }"
                                />
                                <text
                                    class="fleet-label"
                                    :x="projectPoint(focusTrip.fleet.base_latitude, focusTrip.fleet.base_longitude).x + 18"
                                    :y="projectPoint(focusTrip.fleet.base_latitude, focusTrip.fleet.base_longitude).y - 12"
                                >
                                    Pool armada
                                </text>
                            </g>

                            <g v-for="student in students" :key="student.id">
                                <circle
                                    class="student-dot"
                                    :cx="projectPoint(student.latitude, student.longitude).x"
                                    :cy="projectPoint(student.latitude, student.longitude).y"
                                    :r="studentRadius(student)"
                                    :fill="studentFill(student.id)"
                                    :opacity="studentOpacity(student)"
                                    :class="{
                                        'is-active': activeStudent?.id === student.id,
                                        'is-focus': isStudentHighlighted(student.id),
                                    }"
                                />
                                <text
                                    v-if="routeOrderByStudentId.has(student.id)"
                                    class="order-label"
                                    :x="projectPoint(student.latitude, student.longitude).x"
                                    :y="projectPoint(student.latitude, student.longitude).y + 4"
                                    text-anchor="middle"
                                >
                                    {{ routeOrderByStudentId.get(student.id) }}
                                </text>
                            </g>

                            <g v-if="activeStudent">
                                <text
                                    class="active-student-label"
                                    :x="projectPoint(activeStudent.latitude, activeStudent.longitude).x + 18"
                                    :y="projectPoint(activeStudent.latitude, activeStudent.longitude).y + 28"
                                >
                                    {{ activeStudent.name }}
                                </text>
                            </g>
                        </svg>
                    </div>

                    <div class="detail-scrubber" v-if="displayMode === 'detail'">
                        <label for="detail-event-range">
                            Event {{ detailEventIndex + 1 }} dari {{ events.length }}
                        </label>
                        <input
                            id="detail-event-range"
                            v-model.number="detailEventIndex"
                            type="range"
                            min="0"
                            :max="Math.max(events.length - 1, 0)"
                            @input="stopPlayback"
                        />
                    </div>
                </article>

                <aside class="explain-panel">
                    <section class="explain-card is-primary">
                        <p class="route-viz-kicker">Inti langkah</p>
                        <h3>{{ activeChapter?.title }}</h3>
                        <p>{{ activeChapter?.takeaway }}</p>
                    </section>

                    <section class="fact-grid" aria-label="Fakta langkah aktif">
                        <div v-for="fact in chapterFacts" :key="fact.label">
                            <span>{{ fact.label }}</span>
                            <strong>{{ fact.value }}</strong>
                        </div>
                    </section>

                    <section class="score-panel">
                        <div class="section-title">
                            <p class="route-viz-kicker">Skor rit</p>
                            <span v-if="hiddenScoreCount > 0">+{{ hiddenScoreCount }} rit lain</span>
                        </div>

                        <div v-if="simplifiedScoreRows.length" class="score-list">
                            <div
                                v-for="row in simplifiedScoreRows"
                                :key="row.trip_id"
                                class="score-row"
                                :class="{ 'is-selected': row.selected }"
                            >
                                <div>
                                    <strong>{{ shortTripLabel(row.trip_label) }}</strong>
                                    <span>{{ row.current_load }}/{{ row.capacity }} siswa</span>
                                </div>
                                <b>{{ Number(row.score).toFixed(3) }}</b>
                            </div>
                        </div>

                        <p v-else class="empty-note">
                            Skor ringkas muncul pada langkah "Skor".
                        </p>
                    </section>

                    <section class="formula-panel" v-if="simplifiedScoreRows.length">
                        <p class="route-viz-kicker">Rumus yang ditampilkan</p>
                        <div>
                            <span>Insertion</span>
                            <span>+</span>
                            <span>Kapasitas</span>
                            <span>+</span>
                            <span>Outlier</span>
                            <span>=</span>
                            <strong>Skor</strong>
                        </div>
                    </section>

                    <section class="detail-panel" v-if="displayMode === 'detail'">
                        <div class="section-title">
                            <p class="route-viz-kicker">Detail teknis</p>
                            <span>{{ currentEvent?.phase }}</span>
                        </div>

                        <div class="detail-table" v-if="currentEvent?.score_rows?.length">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Rit</th>
                                        <th>Ins.</th>
                                        <th>Kap.</th>
                                        <th>Out.</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in currentEvent.score_rows"
                                        :key="row.trip_id"
                                        :class="{ 'is-selected': row.selected }"
                                    >
                                        <td>{{ shortTripLabel(row.trip_label) }}</td>
                                        <td>{{ row.insertion_cost_km?.toFixed(3) ?? "-" }}</td>
                                        <td>{{ row.capacity_penalty?.toFixed(3) ?? "-" }}</td>
                                        <td>{{ row.outlier_penalty?.toFixed(3) ?? "-" }}</td>
                                        <td>{{ row.score?.toFixed(3) ?? "-" }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p v-else class="empty-note">
                            Event ini tidak memiliki tabel skor.
                        </p>
                    </section>
                </aside>
            </section>
            </template>
        </main>
    </AuthenticatedLayout>
</template>

<style scoped>
/* Hallmark · macrostructure: Focus Workspace · tone: instructional-cartographic · anchor hue: cobalt · pre-emit critique: P5 H5 E4 S5 R5 V5 */
/* Hallmark · genre: modern-minimal · theme: system-managed · enrichment: live basemap + user-controlled camera · nav: existing app shell · footer: none · contrast: pass (40–41) · slop: pass (42–45) · honest: pass (46) · chrome: pass (47) · tokens: pass (48) · icons: pass (30) · mobile: pass (34, 49–57) */
:global(html),
:global(body) {
    overflow-x: clip;
}

.route-viz,
.route-viz-header {
    --color-paper: oklch(98% 0.006 88);
    --color-paper-2: oklch(96% 0.01 88);
    --color-panel: oklch(99.5% 0.003 88);
    --color-panel-2: oklch(97.5% 0.008 88);
    --color-ink: oklch(22% 0.028 254);
    --color-ink-soft: oklch(35% 0.024 254);
    --color-muted: oklch(47% 0.02 254);
    --color-rule: oklch(88% 0.014 88);
    --color-rule-strong: oklch(78% 0.026 254);
    --color-accent: oklch(52% 0.18 255);
    --color-accent-soft: oklch(95% 0.035 255);
    --color-accent-ink: oklch(99% 0.006 88);
    --color-success: oklch(49% 0.14 151);
    --color-success-soft: oklch(95% 0.035 151);
    --color-warning: oklch(62% 0.14 58);
    --color-warning-soft: oklch(95% 0.045 72);
    --color-danger: oklch(52% 0.19 28);
    --color-danger-soft: oklch(95% 0.035 28);
    --color-student-idle: oklch(70% 0.018 254);
    --trip-1: oklch(53% 0.17 258);
    --trip-2: oklch(52% 0.14 150);
    --trip-3: oklch(63% 0.16 58);
    --trip-4: oklch(53% 0.16 305);
    --trip-5: oklch(55% 0.18 28);
    --trip-6: oklch(55% 0.13 210);
    --trip-7: oklch(58% 0.13 96);
    --trip-8: oklch(50% 0.14 285);
    --font-display: Figtree, ui-sans-serif, system-ui, sans-serif;
    --font-body: Figtree, ui-sans-serif, system-ui, sans-serif;
    --space-2xs: 0.25rem;
    --space-xs: 0.5rem;
    --space-sm: 0.75rem;
    --space-md: 1rem;
    --space-lg: 1.5rem;
    --space-xl: 2.5rem;
    --radius-sm: 0.5rem;
    --radius-md: 0.75rem;
    --radius-lg: 1rem;
    --dur-micro: 120ms;
    --dur-short: 200ms;
    --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
    --ease-in: cubic-bezier(0.7, 0, 0.84, 0);
}

.route-viz {
    min-height: calc(100dvh - 8rem);
    background: var(--color-paper);
    color: var(--color-ink);
    font-family: var(--font-body);
    padding: var(--space-md);
}

.route-viz-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    color: var(--color-ink);
}

.route-viz-header h2 {
    margin: 0;
    font-family: var(--font-display);
    font-size: clamp(1.25rem, 1.2vw + 1rem, 1.85rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.12;
}

.route-viz-header.is-focus-stage {
    align-items: center;
    min-height: 2.75rem;
}

.focus-page-title {
    display: grid;
    gap: var(--space-2xs);
    grid-template-columns: minmax(0, 1fr);
    min-width: 0;
}

.focus-page-title span {
    color: var(--color-accent);
    font-size: 0.7rem;
    font-weight: 850;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.focus-page-title strong {
    font-family: var(--font-display);
    font-size: 1.15rem;
    overflow-wrap: anywhere;
}

.route-viz-kicker {
    margin: 0;
    color: var(--color-accent);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    line-height: 1.2;
    text-transform: uppercase;
}

.route-viz-link,
.route-viz-actions button,
.route-viz-mode button {
    align-items: center;
    border-radius: var(--radius-sm);
    display: inline-flex;
    font-weight: 800;
    justify-content: center;
    min-height: 2.75rem;
    text-decoration: none;
    transition:
        background-color var(--dur-short) var(--ease-out),
        border-color var(--dur-short) var(--ease-out),
        color var(--dur-short) var(--ease-out),
        transform var(--dur-micro) var(--ease-out);
    white-space: nowrap;
}

.route-viz-link {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    color: var(--color-ink-soft);
    padding-inline: var(--space-md);
}

.route-viz-toolbar {
    align-items: end;
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: minmax(0, auto) minmax(0, 1fr) minmax(0, auto);
    margin-inline: auto;
    max-width: 92rem;
}

.route-viz-mode {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    display: flex;
    padding: var(--space-2xs);
}

.route-viz-mode button {
    background: transparent;
    border: 0;
    color: var(--color-muted);
    padding-inline: var(--space-md);
}

.route-viz-mode button[aria-pressed="true"] {
    background: var(--color-ink);
    color: var(--color-panel);
}

.route-viz-filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    justify-content: flex-end;
}

.route-viz-filters label {
    color: var(--color-muted);
    display: grid;
    font-size: 0.7rem;
    font-weight: 800;
    gap: var(--space-2xs);
    letter-spacing: 0.08em;
    min-width: 11rem;
    text-transform: uppercase;
}

.route-viz-filters select {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    color: var(--color-ink);
    font-size: 0.9rem;
    font-weight: 750;
    min-height: 2.75rem;
    outline: 2px solid transparent;
    outline-offset: 1px;
}

.route-viz-actions {
    display: flex;
    gap: var(--space-xs);
    justify-content: flex-end;
}

.route-viz-actions button {
    border: 1px solid var(--color-rule);
    outline: 2px solid transparent;
    outline-offset: 1px;
    padding-inline: var(--space-md);
}

.button-primary {
    background: var(--color-accent);
    color: var(--color-accent-ink);
}

.button-secondary {
    background: var(--color-panel);
    color: var(--color-ink-soft);
}

.route-viz-link:hover,
.route-viz-actions button:hover,
.route-viz-mode button:hover {
    transform: translateY(-1px);
}

.route-viz-actions button:active,
.route-viz-mode button:active,
.route-viz-link:active {
    transform: translateY(1px);
}

.route-viz-actions button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
    transform: none;
}

.route-viz-link:focus-visible,
.route-viz-actions button:focus-visible,
.route-viz-mode button:focus-visible,
.route-viz-filters select:focus-visible,
.chapter-strip button:focus-visible,
.detail-scrubber input:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}

.route-viz-overview {
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin: var(--space-sm) auto;
    max-width: 92rem;
}

.route-viz-overview div {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    padding: var(--space-sm) var(--space-md);
}

.route-viz-overview span {
    display: block;
    font-size: 1.4rem;
    font-variant-numeric: tabular-nums;
    font-weight: 850;
    letter-spacing: -0.03em;
    line-height: 1;
}

.route-viz-overview p {
    color: var(--color-muted);
    font-size: 0.78rem;
    font-weight: 750;
    margin: var(--space-2xs) 0 0;
}

.route-viz-workbench {
    display: grid;
    gap: var(--space-md);
    grid-template-columns: minmax(0, 1fr) minmax(20rem, 25rem);
    margin-inline: auto;
    max-width: 92rem;
}

.map-panel,
.explain-panel > section {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-lg);
}

.map-panel {
    min-width: 0;
    padding: var(--space-md);
}

.chapter-strip {
    display: grid;
    gap: var(--space-xs);
    grid-template-columns: repeat(6, minmax(0, 1fr));
    margin-bottom: var(--space-md);
}

.chapter-strip button {
    background: var(--color-panel-2);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    color: var(--color-muted);
    display: grid;
    font-size: 0.78rem;
    font-weight: 850;
    gap: var(--space-2xs);
    min-height: 4rem;
    outline: 2px solid transparent;
    outline-offset: 1px;
    padding: var(--space-xs);
    text-align: left;
}

.chapter-strip button span {
    color: var(--color-accent);
    font-size: 0.68rem;
    letter-spacing: 0.1em;
}

.chapter-strip button[aria-pressed="true"] {
    background: var(--color-ink);
    border-color: var(--color-ink);
    color: var(--color-panel);
}

.chapter-strip button[aria-pressed="true"] span {
    color: var(--color-accent-ink);
}

.map-panel-head {
    align-items: start;
    display: grid;
    gap: var(--space-md);
    grid-template-columns: minmax(0, 1fr) minmax(12rem, auto);
    margin-bottom: var(--space-md);
}

.map-panel-head h3,
.explain-card h3 {
    font-size: clamp(1.2rem, 0.9vw + 1rem, 1.7rem);
    font-weight: 850;
    letter-spacing: -0.035em;
    line-height: 1.12;
    margin: var(--space-xs) 0 var(--space-xs);
    overflow-wrap: anywhere;
}

.map-panel-head p:not(.route-viz-kicker),
.explain-card p:not(.route-viz-kicker) {
    color: var(--color-muted);
    font-size: 0.95rem;
    line-height: 1.55;
    margin: 0;
    max-width: 70ch;
}

.map-focus {
    background: var(--color-accent-soft);
    border: 1px solid color-mix(in oklch, var(--color-accent) 22%, var(--color-rule));
    border-radius: var(--radius-md);
    color: var(--color-ink);
    padding: var(--space-sm);
}

.map-focus span {
    color: var(--color-accent);
    display: block;
    font-size: 0.68rem;
    font-weight: 850;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.map-focus strong {
    display: block;
    font-size: 0.9rem;
    line-height: 1.3;
    margin-top: var(--space-2xs);
}

.map-canvas {
    background: linear-gradient(180deg, var(--color-paper-2), var(--color-paper));
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.map-canvas svg {
    display: block;
    height: min(49dvh, 34rem);
    min-height: 27rem;
    width: 100%;
}

.map-canvas-bg {
    fill: var(--color-paper-2);
}

.route-line {
    opacity: 0.78;
    stroke-dasharray: 12 12;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 5;
}

.route-line.is-final {
    stroke-dasharray: 0;
}

.school-marker {
    fill: var(--color-success-soft);
    stroke: var(--color-success);
    stroke-width: 3;
}

.school-label,
.fleet-label,
.active-student-label {
    fill: var(--color-ink);
    font-family: var(--font-body);
    font-size: 18px;
    font-weight: 850;
}

.fleet-marker {
    fill: var(--trip-color);
}

.fleet-label {
    fill: var(--color-muted);
    font-size: 15px;
}

.student-dot {
    stroke: var(--color-panel);
    stroke-width: 1.5;
}

.student-dot.is-focus {
    stroke-width: 2.5;
}

.student-dot.is-active {
    stroke: var(--color-ink);
    stroke-width: 4;
}

.order-label {
    fill: var(--color-panel);
    font-family: var(--font-body);
    font-size: 10px;
    font-weight: 900;
}

.active-student-label {
    font-size: 20px;
}

.detail-scrubber {
    display: grid;
    gap: var(--space-xs);
    margin-top: var(--space-sm);
}

.detail-scrubber label {
    color: var(--color-muted);
    font-size: 0.78rem;
    font-weight: 800;
}

.detail-scrubber input {
    accent-color: var(--color-accent);
}

.explain-panel {
    display: grid;
    gap: var(--space-md);
    min-width: 0;
}

.explain-panel > section {
    padding: var(--space-md);
}

.explain-card.is-primary {
    background: var(--color-ink);
    color: var(--color-panel);
}

.explain-card.is-primary .route-viz-kicker,
.explain-card.is-primary p {
    color: color-mix(in oklch, var(--color-panel) 78%, var(--color-accent));
}

.fact-grid {
    display: grid;
    gap: var(--space-xs);
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.fact-grid div {
    background: var(--color-panel-2);
    border-radius: var(--radius-sm);
    padding: var(--space-sm);
}

.fact-grid span,
.score-row span,
.section-title span {
    color: var(--color-muted);
    display: block;
    font-size: 0.72rem;
    font-weight: 800;
}

.fact-grid strong {
    display: block;
    font-size: 0.95rem;
    font-variant-numeric: tabular-nums;
    font-weight: 850;
    line-height: 1.25;
    margin-top: var(--space-2xs);
    overflow-wrap: anywhere;
}

.section-title {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.score-list {
    display: grid;
    gap: var(--space-xs);
}

.score-row {
    align-items: center;
    background: var(--color-panel-2);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: minmax(0, 1fr) auto;
    padding: var(--space-sm);
}

.score-row.is-selected {
    background: var(--color-success-soft);
    border-color: color-mix(in oklch, var(--color-success) 34%, var(--color-rule));
}

.score-row strong {
    display: block;
    font-size: 0.9rem;
    line-height: 1.25;
}

.score-row b {
    color: var(--color-ink);
    font-size: 1.1rem;
    font-variant-numeric: tabular-nums;
}

.formula-panel div {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
    margin-top: var(--space-sm);
}

.formula-panel span,
.formula-panel strong {
    background: var(--color-panel-2);
    border: 1px solid var(--color-rule);
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 850;
    padding: var(--space-xs) var(--space-sm);
    white-space: nowrap;
}

.formula-panel strong {
    background: var(--color-accent-soft);
    color: var(--color-accent);
}

.detail-table {
    max-height: 18rem;
    overflow: auto;
}

.detail-table table {
    border-collapse: collapse;
    font-size: 0.76rem;
    width: 100%;
}

.detail-table th,
.detail-table td {
    border-bottom: 1px solid var(--color-rule);
    padding: var(--space-xs);
    text-align: right;
    vertical-align: top;
}

.detail-table th:first-child,
.detail-table td:first-child {
    text-align: left;
}

.detail-table tr.is-selected td {
    background: var(--color-success-soft);
    font-weight: 850;
}

.empty-note {
    color: var(--color-muted);
    font-size: 0.9rem;
    line-height: 1.45;
    margin: 0;
}

/* Thesis playback: one stage, one decision, one visual grammar. */
.lesson-toolbar,
.lesson-shell {
    margin-inline: auto;
    max-width: 96rem;
}

.lesson-toolbar {
    align-items: end;
    display: grid;
    gap: var(--space-md);
    grid-template-columns: auto minmax(0, 1fr) auto;
    margin-bottom: var(--space-md);
}

.route-viz.is-focus-stage {
    padding-top: var(--space-sm);
}

.lesson-toolbar.is-focus-stage {
    align-items: center;
    gap: var(--space-sm);
    grid-template-columns: auto minmax(0, 1fr);
    margin-bottom: var(--space-sm);
}

.lesson-toolbar.is-focus-stage .lesson-filters {
    justify-content: end;
}

.lesson-mode {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    display: flex;
    padding: var(--space-2xs);
}

.lesson-mode button,
.lesson-actions button {
    align-items: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: calc(var(--radius-sm) - 0.125rem);
    color: var(--color-ink-soft);
    display: inline-flex;
    font: 800 0.86rem/1 var(--font-body);
    justify-content: center;
    min-height: 2.75rem;
    padding-inline: var(--space-md);
    transition:
        background-color var(--dur-short) var(--ease-out),
        border-color var(--dur-short) var(--ease-out),
        color var(--dur-short) var(--ease-out),
        transform var(--dur-micro) var(--ease-out);
    white-space: nowrap;
}

.lesson-mode button[aria-pressed="true"] {
    background: var(--color-ink);
    color: var(--color-panel);
}

.lesson-summary {
    align-items: center;
    color: var(--color-muted);
    display: flex;
    font-size: 0.92rem;
    gap: var(--space-sm);
    justify-content: center;
    margin: 0 0 var(--space-sm);
}

.lesson-summary strong {
    color: var(--color-ink);
    font-variant-numeric: tabular-nums;
}

.lesson-filters {
    display: flex;
    gap: var(--space-sm);
}

.lesson-filters label {
    display: grid;
    gap: var(--space-2xs);
}

.lesson-filters label > span {
    color: var(--color-muted);
    font-size: 0.68rem;
    font-weight: 850;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.lesson-filters select {
    background: var(--color-panel);
    border: 1px solid var(--color-rule-strong);
    border-radius: var(--radius-sm);
    color: var(--color-ink);
    font-size: 0.86rem;
    font-weight: 750;
    min-height: 2.75rem;
    min-width: 13rem;
    padding-inline: var(--space-sm) 2.25rem;
}

.lesson-shell {
    background: transparent;
    border: 0;
    border-radius: 0;
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: 9.5rem minmax(0, 1fr) 19rem;
    min-height: 37rem;
    overflow: visible;
}

.lesson-shell.is-map-stage {
    grid-template-columns: minmax(0, 1fr);
    max-width: 112rem;
}

.lesson-shell.is-map-stage .stage-rail {
    border-radius: var(--radius-sm);
    grid-template-columns: repeat(6, minmax(0, 1fr));
    grid-template-rows: auto;
    min-height: 0;
    padding: var(--space-2xs);
}

.lesson-shell.is-map-stage .stage-rail > p {
    display: none;
}

.lesson-shell.is-map-stage .stage-rail button {
    align-items: center;
    border-inline-start: 1px solid var(--color-rule);
    border-top: 0;
    display: flex;
    gap: var(--space-xs);
    justify-content: center;
    min-height: 2.5rem;
    padding: var(--space-xs);
    text-align: center;
}

.lesson-shell.is-map-stage .stage-rail button:first-of-type {
    border-inline-start: 0;
}

.lesson-shell.is-map-stage .stage-rail button[aria-current="step"] {
    box-shadow: inset 0 -3px 0 var(--color-accent);
}

.lesson-shell.is-map-stage .lesson-stage {
    padding: var(--space-lg);
}

.lesson-shell.is-map-stage .lesson-stage-head {
    display: none;
}

.lesson-shell.is-map-stage .stage-number {
    font-size: 2rem;
    margin: 0;
}

.lesson-shell.is-map-stage .teaching-canvas {
    align-items: stretch;
    min-height: 0;
    padding-block: var(--space-sm) 0;
}

.stage-rail {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    box-shadow: 0 0.5rem 1.5rem color-mix(in oklch, var(--color-ink) 7%, transparent);
    display: grid;
    grid-template-rows: auto repeat(6, minmax(0, 1fr));
    padding: var(--space-md) var(--space-sm);
}

.stage-rail > p {
    color: var(--color-muted);
    font-size: 0.66rem;
    font-weight: 850;
    letter-spacing: 0.12em;
    margin: 0 0 var(--space-sm);
    text-transform: uppercase;
}

.stage-rail button {
    align-content: center;
    background: transparent;
    border: 0;
    border-top: 1px solid var(--color-rule);
    color: var(--color-muted);
    display: grid;
    gap: var(--space-2xs);
    min-height: 3.5rem;
    padding: var(--space-sm) var(--space-xs);
    text-align: start;
    transition:
        background-color var(--dur-short) var(--ease-out),
        color var(--dur-short) var(--ease-out),
        transform var(--dur-micro) var(--ease-out);
}

.stage-rail button span {
    font-size: 0.68rem;
    font-variant-numeric: tabular-nums;
    font-weight: 850;
}

.stage-rail button strong {
    color: inherit;
    font-size: 0.94rem;
}

.stage-rail button[aria-current="step"] {
    background: var(--color-accent);
    border-color: transparent;
    border-radius: var(--radius-sm);
    color: var(--color-accent-ink);
    box-shadow: none;
}

.lesson-stage {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    box-shadow: 0 0.5rem 1.5rem color-mix(in oklch, var(--color-ink) 7%, transparent);
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    min-width: 0;
    padding: var(--space-xl);
}

.lesson-stage-head {
    border-bottom: 1px solid var(--color-rule);
    display: block;
    padding-bottom: var(--space-md);
}

.stage-number {
    color: var(--color-accent);
    font-size: 2.4rem;
    font-variant-numeric: tabular-nums;
    font-weight: 900;
    letter-spacing: -0.06em;
    line-height: 1;
    margin-bottom: var(--space-xs);
}

.lesson-stage-head p {
    color: var(--color-accent);
    font-size: 0.68rem;
    font-weight: 850;
    letter-spacing: 0.12em;
    margin: 0;
    text-transform: uppercase;
}

.lesson-stage-head h3 {
    font-family: var(--font-display);
    font-size: clamp(1.3rem, 1.4vw + 0.7rem, 2rem);
    font-weight: 850;
    letter-spacing: -0.035em;
    line-height: 1.1;
    margin: var(--space-2xs) 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.lesson-stage-head span {
    color: var(--color-muted);
    display: block;
    font-size: 0.96rem;
    line-height: 1.5;
    max-width: 68ch;
}

.teaching-canvas {
    align-items: center;
    display: grid;
    min-height: 25rem;
    padding-block: var(--space-md);
}

.filter-visual {
    align-items: center;
    display: grid;
    gap: var(--space-lg);
    grid-template-columns: minmax(8rem, 0.7fr) minmax(13rem, 1.3fr) minmax(9rem, 0.8fr);
}

.input-pile {
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: repeat(4, 1.2rem);
    justify-content: center;
}

.input-pile > span {
    background: var(--color-student-idle);
    border-radius: 50%;
    height: 1.2rem;
    opacity: 0.65;
    width: 1.2rem;
}

.input-pile strong {
    color: var(--color-ink-soft);
    font-size: 0.84rem;
    grid-column: 1 / -1;
    text-align: center;
}

.filter-gates {
    display: grid;
    gap: var(--space-xs);
}

.filter-gates div {
    align-items: center;
    background: var(--color-panel-2);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    display: grid;
    gap: var(--space-sm);
    grid-template-columns: auto minmax(0, 1fr);
    padding: var(--space-sm);
}

.filter-gates span {
    align-items: center;
    background: var(--color-accent);
    border-radius: 50%;
    color: var(--color-accent-ink);
    display: inline-flex;
    font-size: 0.65rem;
    font-weight: 900;
    height: 1.75rem;
    justify-content: center;
    width: 1.75rem;
}

.filter-gates strong { font-size: 0.88rem; }

.filter-result {
    background: var(--color-success-soft);
    border: 1px solid color-mix(in oklch, var(--color-success) 35%, var(--color-rule));
    border-radius: var(--radius-md);
    color: var(--color-ink);
    display: grid;
    padding: var(--space-lg);
    text-align: center;
}

.filter-result span,
.filter-result small { color: var(--color-ink-soft); }
.filter-result strong { color: var(--color-success); font-size: 3.25rem; font-variant-numeric: tabular-nums; line-height: 1; margin-block: var(--space-xs); }

.priority-visual { margin-inline: auto; max-width: 45rem; width: 100%; }
.priority-head { align-items: end; display: flex; justify-content: space-between; margin-bottom: var(--space-sm); }
.priority-head div { display: grid; }
.priority-head span { color: var(--color-muted); font-size: 0.72rem; font-weight: 800; }
.priority-head strong { font-size: 1.05rem; }
.priority-head p { color: var(--color-accent); font-size: 0.8rem; font-weight: 850; margin: 0; }
.priority-list { display: grid; gap: var(--space-xs); list-style: none; margin: 0; padding: 0; }
.priority-list li { align-items: center; display: grid; gap: var(--space-sm); grid-template-columns: 2rem minmax(0, 1fr) 4.75rem; }
.priority-list li > b { align-items: center; background: var(--color-ink); border-radius: 50%; color: var(--color-panel); display: flex; font-size: 0.76rem; height: 2rem; justify-content: center; }
.priority-list li > div { display: grid; gap: var(--space-2xs); }
.priority-list li span { font-size: 0.84rem; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.priority-list li i { background: var(--color-accent); border-radius: 999px; display: block; height: 0.45rem; }
.priority-list li > strong { font-size: 0.84rem; font-variant-numeric: tabular-nums; text-align: end; }
.decision-line { background: var(--color-accent-soft); border-radius: var(--radius-sm); color: var(--color-ink-soft); font-size: 0.84rem; line-height: 1.45; margin-top: var(--space-md); padding: var(--space-sm); }
.decision-line span { color: var(--color-accent); font-weight: 900; margin-inline-end: var(--space-xs); }

.score-visual { align-items: stretch; display: grid; gap: var(--space-md); grid-template-columns: minmax(9rem, 0.75fr) auto minmax(15rem, 1.75fr); }
.candidate-card { align-self: center; background: var(--color-accent-soft); border: 1px solid color-mix(in oklch, var(--color-accent) 35%, var(--color-rule)); border-radius: var(--radius-md); display: grid; padding: var(--space-lg); }
.candidate-card span { color: var(--color-accent); font-size: 0.7rem; font-weight: 850; text-transform: uppercase; }
.candidate-card strong { font-size: 1.15rem; line-height: 1.2; margin-block: var(--space-xs); }
.candidate-card small { color: var(--color-muted); }
.choice-arrow { align-items: center; color: var(--color-accent); display: flex; flex-direction: column; font-size: 1.75rem; justify-content: center; }
.choice-arrow span { color: var(--color-muted); font-size: 0.68rem; white-space: nowrap; }
.score-choices { display: grid; gap: var(--space-xs); }
.score-choice { background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: grid; gap: var(--space-xs); padding: var(--space-sm); }
.score-choice.is-winner { background: var(--color-success-soft); border-color: color-mix(in oklch, var(--color-success) 40%, var(--color-rule)); box-shadow: inset 3px 0 0 var(--color-success); }
.score-choice-head { align-items: center; display: flex; justify-content: space-between; }
.score-choice-head span { color: var(--color-muted); font-size: 0.66rem; font-weight: 850; text-transform: uppercase; }
.score-choice.is-winner .score-choice-head span { color: var(--color-success); }
.score-choice-head strong { font-size: 1.15rem; font-variant-numeric: tabular-nums; }
.score-choice > b { font-size: 0.82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.score-breakdown { display: grid; gap: var(--space-2xs); }
.score-breakdown span { color: var(--color-muted); font-size: 0.68rem; overflow: hidden; position: relative; white-space: nowrap; }
.score-breakdown i { background: var(--color-accent); display: inline-block; height: 0.3rem; margin-inline-end: var(--space-xs); max-width: 42%; min-width: 0.25rem; vertical-align: middle; }

.improve-visual { display: grid; gap: var(--space-sm); margin-inline: auto; max-width: 46rem; width: 100%; }
.improve-method-tabs { background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: var(--space-2xs); }
.improve-method-tabs button { background: transparent; border: 0; border-radius: calc(var(--radius-sm) - 0.125rem); color: var(--color-muted); font: 800 0.76rem/1 var(--font-body); min-height: 2.75rem; padding-inline: var(--space-sm); white-space: nowrap; }
.improve-method-tabs button span { color: var(--color-accent); margin-inline-end: var(--space-2xs); }
.improve-method-tabs button.is-active { background: var(--color-ink); color: var(--color-panel); }
.improve-method-tabs button.is-active span { color: var(--color-panel); }
.improve-method-tabs button:focus-visible { outline: 2px solid var(--color-warning); outline-offset: 2px; }
.improve-method-tabs button:active { transform: translateY(1px); }
.improve-method-tabs button:disabled { cursor: not-allowed; opacity: 0.5; }
.improve-rules { background: var(--color-success-soft); border: 1px solid color-mix(in oklch, var(--color-success) 32%, var(--color-rule)); border-radius: var(--radius-sm); display: grid; gap: var(--space-xs); grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); padding: var(--space-sm); }
.improve-rules.is-tradeoff { background: var(--color-warning-soft); border-color: color-mix(in oklch, var(--color-warning) 42%, var(--color-rule)); }
.improve-rules > span { align-items: center; color: var(--color-ink-soft); display: flex; font-size: 0.875rem; font-weight: 750; gap: var(--space-xs); line-height: 1.35; }
.improve-rules b { align-items: center; background: var(--color-success); border-radius: 50%; color: var(--color-panel); display: inline-flex; flex: 0 0 auto; font-size: 0.65rem; height: 1.45rem; justify-content: center; width: 1.45rem; }
.improve-rules.is-tradeoff b { background: var(--color-warning); color: var(--color-ink); }
.before-after-labels { color: var(--color-muted); display: grid; font-size: 0.7rem; font-weight: 850; grid-template-columns: 12rem minmax(0, 1fr) 2rem minmax(0, 1fr); letter-spacing: 0.08em; margin-bottom: var(--space-xs); text-transform: uppercase; }
.before-after-labels span:first-child { grid-column: 2; }
.before-after-labels span:last-child { grid-column: 4; }
.load-comparison { align-items: center; border-top: 1px solid var(--color-rule); display: grid; gap: var(--space-sm); grid-template-columns: 12rem minmax(0, 1fr) 2rem minmax(0, 1fr); padding-block: var(--space-md); }
.load-name { display: grid; min-width: 0; }
.load-name span { color: var(--color-accent); font-size: 0.68rem; font-weight: 850; text-transform: uppercase; }
.load-name strong { font-size: 0.78rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.load-box { background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); padding: var(--space-sm); }
.load-box b { display: block; font-variant-numeric: tabular-nums; margin-bottom: var(--space-xs); }
.load-box > span { background: var(--color-rule); border-radius: 999px; display: block; height: 0.5rem; overflow: hidden; }
.load-box i { background: var(--color-warning); display: block; height: 100%; }
.load-box.is-after { background: var(--color-success-soft); border-color: color-mix(in oklch, var(--color-success) 35%, var(--color-rule)); }
.load-box.is-after i { background: var(--color-success); }
.move-arrow { color: var(--color-accent); font-size: 1.4rem; text-align: center; }
.move-result { align-items: center; background: var(--color-accent-soft); border-radius: var(--radius-sm); display: flex; justify-content: space-between; margin-top: var(--space-sm); padding: var(--space-sm); }
.move-result span { font-size: 0.82rem; font-weight: 800; }
.move-result strong { color: var(--color-accent); font-size: 0.9rem; font-variant-numeric: tabular-nums; }
.move-result.is-tradeoff { background: var(--color-warning-soft); }
.move-result.is-tradeoff strong { color: var(--color-ink); }
.no-change { background: var(--color-panel-2); border: 1px dashed var(--color-rule-strong); border-radius: var(--radius-sm); color: var(--color-muted); padding: var(--space-lg); text-align: center; }

.route-visual { display: grid; gap: var(--space-sm); min-width: 0; }
.route-learning-toolbar { align-items: end; display: grid; gap: var(--space-sm); grid-template-columns: minmax(10rem, 0.65fr) auto minmax(12rem, 1fr) auto; }
.focus-route-heading { display: grid; gap: var(--space-2xs); min-width: 0; }
.focus-route-heading span { color: var(--color-accent); font-size: 0.65rem; font-weight: 850; letter-spacing: 0.1em; text-transform: uppercase; }
.focus-route-heading strong { font-size: 1rem; line-height: 1.2; overflow-wrap: anywhere; }
.route-method-tabs { background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: flex; padding: var(--space-2xs); }
.route-method-tabs button { background: transparent; border: 0; border-radius: calc(var(--radius-sm) - 0.125rem); color: var(--color-muted); font: 800 0.76rem/1 var(--font-body); min-height: 2.75rem; padding-inline: var(--space-sm); white-space: nowrap; }
.route-method-tabs button span { color: var(--color-accent); margin-inline-end: var(--space-2xs); }
.route-method-tabs button.is-active { background: var(--color-ink); color: var(--color-panel); }
.route-method-tabs button.is-active span { color: var(--color-panel); }
.route-method-tabs button:disabled { cursor: not-allowed; opacity: 0.5; }
.route-micro-stepper { align-items: center; display: flex; gap: var(--space-xs); }
.route-micro-stepper strong { color: var(--color-ink-soft); font-size: 0.74rem; min-width: 10.5rem; text-align: center; }
.route-micro-stepper button { align-items: center; background: var(--color-panel); border: 1px solid var(--color-rule-strong); border-radius: var(--radius-sm); color: var(--color-ink); display: inline-flex; font-size: 0.75rem; font-weight: 850; gap: var(--space-2xs); height: 2.75rem; justify-content: center; padding-inline: var(--space-sm); white-space: nowrap; }
.route-micro-stepper button.is-primary { background: var(--color-accent); border-color: var(--color-accent); color: var(--color-accent-ink); }
.route-micro-stepper button:disabled { cursor: not-allowed; opacity: 0.35; }
.teaching-trip-control { display: grid; gap: var(--space-2xs); justify-self: center; max-width: 22rem; min-width: 0; width: 100%; }
.teaching-trip-control > span { color: var(--color-muted); font-size: 0.66rem; font-weight: 850; letter-spacing: 0.08em; text-transform: uppercase; }
.teaching-trip-control select { background: var(--color-panel); border: 1px solid var(--color-rule-strong); border-radius: var(--radius-sm); color: var(--color-ink); font: 750 0.8rem/1.2 var(--font-body); min-height: 2.75rem; min-width: 0; padding-inline: var(--space-sm) 2.25rem; width: 100%; }
.teaching-trip-control select:disabled { cursor: not-allowed; opacity: 0.55; }
.route-method-tabs button:focus-visible,
.route-micro-stepper button:focus-visible,
.teaching-trip-control select:focus-visible { outline: 2px solid var(--color-warning); outline-offset: 2px; }
.route-method-tabs button:active,
.route-micro-stepper button:not(:disabled):active { transform: translateY(1px); }
@media (hover: hover) and (pointer: fine) {
    .route-method-tabs button:not(.is-active):not(:disabled):hover,
    .route-micro-stepper button:not(:disabled):hover,
    .improve-method-tabs button:not(.is-active):hover { background: var(--color-accent-soft); color: var(--color-accent); }
}

.route-stage-result {
    align-items: center;
    background: var(--color-panel-2);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-sm);
    display: grid;
    gap: var(--space-md);
    grid-template-columns: minmax(0, 1fr) auto;
    padding: var(--space-sm);
}

.route-stage-result-copy {
    display: grid;
    gap: var(--space-2xs);
    min-width: 0;
}

.route-stage-result-copy > span {
    color: var(--color-accent);
    font-size: 0.66rem;
    font-weight: 850;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.route-stage-result-copy > strong {
    font-size: 0.95rem;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.route-stage-actions {
    grid-template-columns: repeat(3, minmax(4.5rem, 1fr));
    margin: 0;
    min-width: 17rem;
    padding: 0;
}

.route-stage-actions .progress-label {
    grid-column: 1 / -1;
    margin: 0;
}

.nearest-explanation { align-items: center; background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: grid; gap: var(--space-sm); grid-template-columns: minmax(9rem, 0.7fr) auto minmax(17rem, 1.8fr); padding: var(--space-sm); }
.nearest-origin { display: grid; gap: var(--space-2xs); }
.nearest-origin span,
.nearest-candidates em { color: var(--color-muted); font-size: 0.75rem; }
.two-opt-explanation span,
.two-opt-explanation small { color: var(--color-muted); font-size: 0.76rem; }
.nearest-origin strong { font-size: 0.88rem; }
.nearest-origin small { color: var(--color-muted); font-size: 0.75rem; }
.nearest-arrow { color: var(--color-accent); font-size: 1.4rem; }
.nearest-candidates { display: grid; gap: var(--space-xs); list-style: none; margin: 0; padding: 0; }
.nearest-candidates li { align-items: center; display: grid; gap: var(--space-xs); grid-template-columns: 1.5rem minmax(0, 1fr) auto 4rem; min-width: 0; }
.nearest-candidates li > b { align-items: center; background: var(--color-panel); border: 1px solid var(--color-rule); border-radius: 50%; color: var(--color-muted); display: flex; font-size: 0.64rem; height: 1.5rem; justify-content: center; }
.nearest-candidates li > span { font-size: 0.8rem; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nearest-candidates li > strong { font-size: 0.8rem; font-variant-numeric: tabular-nums; }
.nearest-candidates em { font-style: normal; text-align: end; }
.nearest-candidates li.is-selected { background: var(--color-success-soft); border-radius: var(--radius-sm); box-shadow: inset 0 0 0 1px color-mix(in oklch, var(--color-success) 42%, var(--color-rule)); margin-inline: calc(var(--space-xs) * -1); padding: var(--space-xs); }
.nearest-candidates li.is-selected > b { background: var(--color-success); border-color: var(--color-success); color: var(--color-panel); }
.nearest-candidates li.is-selected em { color: var(--color-success); font-weight: 850; }

.two-opt-explanation { align-items: stretch; background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: grid; gap: var(--space-xs); grid-template-columns: minmax(16rem, 1.45fr) minmax(14rem, 0.85fr); padding: var(--space-sm); }
.two-opt-explanation > div { border-inline-start: 1px solid var(--color-rule); display: grid; gap: var(--space-2xs); padding-inline: var(--space-sm); }
.two-opt-explanation > div:first-child { border-inline-start: 0; padding-inline-start: 0; }
.two-opt-explanation strong { font-size: 0.88rem; font-variant-numeric: tabular-nums; }
.two-opt-operation { align-content: start; }
.two-opt-endpoints { align-items: center; display: flex; flex-wrap: wrap; gap: var(--space-xs); margin-top: var(--space-xs); }
.two-opt-endpoints span { align-items: center; color: var(--color-ink-soft); display: inline-flex; font-size: 0.875rem; font-weight: 800; gap: var(--space-2xs); min-width: 0; }
.two-opt-endpoints span b { align-items: center; background: var(--color-warning-soft); border: 1px solid var(--color-warning); border-radius: 50%; color: var(--color-ink); display: inline-flex; flex: 0 0 auto; height: 1.55rem; justify-content: center; width: 1.55rem; }
.two-opt-endpoints i { color: var(--color-muted); font-size: 0.68rem; font-style: normal; }
.two-opt-change { align-content: center; }
.two-opt-change > div { align-items: baseline; display: flex; flex-wrap: wrap; gap: var(--space-xs); }
.two-opt-change > div strong:first-child { color: var(--color-muted); }
.two-opt-change > div strong:last-child { color: var(--color-success); }
.two-opt-change > div b { color: var(--color-accent); }
.two-opt-change em { color: var(--color-success); font-size: 0.78rem; font-style: normal; font-weight: 850; }
.two-opt-edge-summary { border-inline-start: 0 !important; border-top: 1px solid var(--color-rule); grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: var(--space-xs) 0 0 !important; }
.two-opt-edge-summary span { color: var(--color-ink-soft); line-height: 1.35; overflow-wrap: anywhere; }
.two-opt-edge-summary b { align-items: center; border-radius: 50%; color: var(--color-panel); display: inline-flex; height: 1.15rem; justify-content: center; margin-inline-end: var(--space-2xs); width: 1.15rem; }
.two-opt-edge-summary b.is-removed { background: var(--color-danger); }
.two-opt-edge-summary b.is-added { background: var(--color-success); }
.two-opt-optimum { grid-column: 1 / -1; }

.route-map { background: var(--color-paper-2); border: 1px solid var(--color-rule); border-radius: var(--radius-md); min-height: 18rem; overflow: hidden; position: relative; }
.route-map-canvas { height: clamp(27rem, calc(100dvh - 20rem), 44rem); min-height: 27rem; width: 100%; }
.route-map-canvas :deep(.leaflet-tile-pane) { filter: saturate(0.55) contrast(0.92) brightness(1.06); }
.route-map-canvas :deep(.leaflet-control-zoom) { border: 1px solid var(--color-rule); box-shadow: 0 0.25rem 0.75rem color-mix(in oklch, var(--color-ink) 10%, transparent); }
.route-map-canvas :deep(.leaflet-control-zoom a) { background: var(--color-panel); color: var(--color-ink); }
.route-map-canvas :deep(.leaflet-control-attribution) { background: color-mix(in oklch, var(--color-panel) 88%, transparent); color: var(--color-muted); font-size: 0.62rem; }
.route-map-canvas :deep(.leaflet-viz-marker) { background: transparent; border: 0; }
.route-map-canvas :deep(.leaflet-viz-marker span),
.map-key i { align-items: center; background: var(--color-panel); border: 2px solid var(--color-accent); border-radius: 50%; box-shadow: 0 0.25rem 0.75rem color-mix(in oklch, var(--color-ink) 18%, transparent); color: var(--color-accent); display: inline-flex; font-size: 0.75rem; font-style: normal; font-weight: 900; height: 2rem; justify-content: center; width: 2rem; }
.route-map-canvas :deep(.leaflet-viz-marker.is-pool span),
.map-key i.is-pool { background: var(--color-ink); border-color: var(--color-panel); border-radius: var(--radius-sm); color: var(--color-panel); }
.route-map-canvas :deep(.leaflet-viz-marker.is-school span),
.map-key i.is-school { background: var(--color-success-soft); border-color: var(--color-success); color: var(--color-success); }
.route-map-canvas :deep(.leaflet-viz-marker.is-candidate span) { background: var(--color-panel-2); border-color: var(--color-muted); border-style: dashed; color: var(--color-muted); }
.route-map-canvas :deep(.leaflet-viz-marker.is-changed span) { background: var(--color-warning-soft); border-color: var(--color-warning); color: var(--color-ink); }
.route-map-canvas :deep(.leaflet-route-line) { filter: drop-shadow(0 0.125rem 0.125rem color-mix(in oklch, var(--color-ink) 20%, transparent)); }
.map-camera-actions { display: flex; gap: var(--space-2xs); inset-block-start: calc(var(--space-xl) + var(--space-xl) + var(--space-xs)); inset-inline-start: var(--space-sm); position: absolute; z-index: 500; }
.map-camera-actions button { background: color-mix(in oklch, var(--color-panel) 94%, transparent); border: 1px solid var(--color-rule-strong); border-radius: var(--radius-sm); color: var(--color-ink); font: 800 0.75rem/1 var(--font-body); min-height: 2.75rem; padding-inline: var(--space-sm); white-space: nowrap; }
.map-camera-actions button:focus-visible { outline: 2px solid var(--color-warning); outline-offset: 2px; }
.map-camera-actions button:active { transform: translateY(1px); }
.map-camera-actions button:disabled { cursor: not-allowed; opacity: 0.5; }
.map-key { align-items: center; background: color-mix(in oklch, var(--color-panel) 94%, transparent); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); box-shadow: 0 0.25rem 0.75rem color-mix(in oklch, var(--color-ink) 9%, transparent); display: flex; flex-wrap: wrap; gap: var(--space-xs) var(--space-sm); inset-inline-end: var(--space-sm); inset-block-start: var(--space-sm); max-width: 23rem; padding: var(--space-xs) var(--space-sm); position: absolute; z-index: 500; }
.map-key span { align-items: center; color: var(--color-ink-soft); display: inline-flex; font-size: 0.7rem; font-weight: 800; gap: var(--space-2xs); white-space: nowrap; }
.map-key i { box-shadow: none; font-size: 0.62rem; height: 1.4rem; width: 1.4rem; }
.map-key i.line-sample { background: transparent; border: 0; border-radius: 0; box-shadow: none; height: 0; width: 1.75rem; }
.map-key i.line-sample.is-choice { border-top: 4px solid var(--color-accent); }
.map-key i.line-sample.is-candidate { border-top: 3px dashed var(--color-muted); }
.map-key i.line-sample.is-before { border-top: 3px dashed var(--color-muted); }
.map-key i.line-sample.is-removed { border-top: 4px dashed var(--color-danger); }
.map-key i.line-sample.is-added { border-top: 4px solid var(--color-success); }
.map-key small { color: var(--color-muted); flex-basis: 100%; font-size: 0.68rem; }
.lesson-shell.is-map-stage .map-key { max-width: 21rem; }
.lesson-shell.is-map-stage .map-key small { display: none; }
.route-step-inspector {
    background: color-mix(in oklch, var(--color-panel) 96%, transparent);
    border: 1px solid var(--color-rule-strong);
    border-radius: var(--radius-sm);
    bottom: var(--space-sm);
    box-shadow: 0 0.5rem 1.5rem color-mix(in oklch, var(--color-ink) 14%, transparent);
    display: grid;
    gap: var(--space-xs);
    left: var(--space-sm);
    max-width: 23rem;
    padding: var(--space-sm);
    position: absolute;
    width: calc(100% - var(--space-lg));
    z-index: 500;
}
.route-step-inspector-head { align-items: center; display: flex; justify-content: space-between; }
.route-step-inspector-head span { color: var(--color-accent); font-size: 0.66rem; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; }
.route-step-inspector-head strong { color: var(--color-muted); font-size: 0.7rem; }
.inspector-decision { color: var(--color-ink-soft); font-size: 0.86rem; margin: 0; }
.inspector-decision strong { color: var(--color-ink); }
.inspector-candidates { display: grid; gap: var(--space-2xs); list-style: none; margin: 0; padding: 0; }
.inspector-candidates li { align-items: center; display: grid; gap: var(--space-xs); grid-template-columns: 1.35rem minmax(0, 1fr) auto; min-width: 0; padding: var(--space-2xs); }
.inspector-candidates li > b { align-items: center; border: 1px solid var(--color-rule); border-radius: 50%; color: var(--color-muted); display: flex; font-size: 0.62rem; height: 1.35rem; justify-content: center; }
.inspector-candidates li > span { font-size: 0.76rem; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inspector-candidates li > strong { font-size: 0.74rem; font-variant-numeric: tabular-nums; }
.inspector-candidates li.is-selected { background: var(--color-success-soft); border-radius: var(--radius-sm); }
.inspector-candidates li.is-selected > b { background: var(--color-success); border-color: var(--color-success); color: var(--color-panel); }
.route-step-inspector > small { color: var(--color-muted); font-size: 0.7rem; }
.inspector-distance { align-items: baseline; display: flex; flex-wrap: wrap; gap: var(--space-xs); }
.inspector-distance span { color: var(--color-muted); font-size: 0.85rem; font-variant-numeric: tabular-nums; }
.inspector-distance b { color: var(--color-accent); }
.inspector-distance strong { color: var(--color-success); font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.inspector-saving { color: var(--color-success); font-size: 0.86rem; font-weight: 900; margin: 0; }
.inspector-saving span { color: var(--color-muted); font-weight: 750; }
.inspector-segment { align-items: center; display: flex; flex-wrap: wrap; font-size: 0.72rem; gap: var(--space-2xs); margin: 0; }
.inspector-segment b { align-items: center; background: var(--color-warning-soft); border: 1px solid var(--color-warning); border-radius: 50%; display: inline-flex; height: 1.35rem; justify-content: center; width: 1.35rem; }
.inspector-segment span { color: var(--color-accent); margin-inline: var(--space-2xs); }
.inspector-details { border-top: 1px solid var(--color-rule); padding-top: var(--space-xs); }
.inspector-details summary { color: var(--color-accent); cursor: pointer; font-size: 0.72rem; font-weight: 850; }
.inspector-details summary:hover { color: var(--color-ink); }
.inspector-details summary:focus-visible { outline: 2px solid var(--color-warning); outline-offset: 2px; }
.inspector-details summary:active { transform: translateY(1px); }
.inspector-details p { color: var(--color-ink-soft); font-size: 0.7rem; line-height: 1.4; margin: var(--space-xs) 0 0; overflow-wrap: anywhere; }
.inspector-details p b { align-items: center; border-radius: 50%; color: var(--color-panel); display: inline-flex; height: 1.1rem; justify-content: center; margin-inline-end: var(--space-2xs); width: 1.1rem; }
.inspector-details p b.is-removed { background: var(--color-danger); }
.inspector-details p b.is-added { background: var(--color-success); }
.map-coordinate-note { background: var(--color-panel); border-top: 1px solid var(--color-rule); color: var(--color-muted); font-size: 0.8rem; line-height: 1.45; margin: 0; padding: var(--space-xs) var(--space-sm); }
.route-sequence { align-items: center; display: flex; gap: var(--space-xs); overflow-x: auto; padding-bottom: var(--space-2xs); }
.route-sequence > span { align-items: center; background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: var(--radius-sm); display: inline-flex; flex: 0 0 auto; font-size: 0.72rem; font-weight: 750; gap: var(--space-xs); max-width: 9rem; overflow: hidden; padding: var(--space-xs); text-overflow: ellipsis; white-space: nowrap; }
.route-sequence > span b { align-items: center; background: var(--color-accent); border-radius: 50%; color: var(--color-accent-ink); display: inline-flex; flex: 0 0 auto; height: 1.3rem; justify-content: center; width: 1.3rem; }
.route-sequence > i { color: var(--color-accent); font-style: normal; }
.route-sequence .sequence-start,
.route-sequence .sequence-end { background: var(--color-success-soft); border-color: color-mix(in oklch, var(--color-success) 35%, var(--color-rule)); color: var(--color-success); }
.sequence-comparison { display: grid; gap: var(--space-sm); grid-template-columns: repeat(2, minmax(0, 1fr)); }
.sequence-comparison > div { align-items: start; display: grid; gap: var(--space-xs); grid-template-columns: minmax(0, 1fr); min-width: 0; }
.sequence-comparison > div > strong { color: var(--color-muted); font-size: 0.68rem; text-transform: uppercase; }
.route-sequence > span.is-changed { background: var(--color-warning-soft); border-color: var(--color-warning); }
.route-sequence.is-after > span.is-changed { background: var(--color-success-soft); border-color: var(--color-success); }
.route-sequence > span.sequence-ellipsis { background: transparent; border-style: dashed; color: var(--color-muted); }
.focus-stage-footer { align-items: center; border-top: 1px solid var(--color-rule); display: grid; gap: var(--space-sm); grid-template-columns: minmax(0, 1fr) auto; padding-top: var(--space-xs); }
.focus-stage-footer > span { color: var(--color-muted); font-size: 0.74rem; overflow-wrap: anywhere; }
.focus-stage-footer .route-stage-actions { grid-template-columns: repeat(3, auto); }

.technical-drawer { border-top: 1px solid var(--color-rule); display: grid; gap: var(--space-xs); padding-top: var(--space-sm); }
.technical-drawer label { color: var(--color-muted); font-size: 0.74rem; font-weight: 800; }
.technical-drawer input { accent-color: var(--color-accent); width: 100%; }
.technical-drawer p { color: var(--color-muted); font-size: 0.78rem; margin: 0; }

.decision-panel {
    background: var(--color-panel);
    border: 1px solid var(--color-rule);
    border-radius: var(--radius-md);
    box-shadow: 0 0.5rem 1.5rem color-mix(in oklch, var(--color-ink) 7%, transparent);
    color: var(--color-ink);
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: var(--space-lg);
}

.decision-kicker { color: var(--color-accent); font-size: 0.7rem; font-weight: 850; letter-spacing: 0.12em; margin: 0; text-transform: uppercase; }
.decision-panel > h3 { font-family: var(--font-display); font-size: clamp(1.3rem, 1vw + 1rem, 1.75rem); font-weight: 850; letter-spacing: -0.035em; line-height: 1.15; margin: var(--space-sm) 0 var(--space-lg); min-width: 0; overflow-wrap: anywhere; }
.decision-panel dl { border-top: 1px solid var(--color-rule); margin: 0; }
.decision-panel dl div { border-bottom: 1px solid var(--color-rule); padding-block: var(--space-sm); }
.decision-panel dt { color: var(--color-muted); font-size: 0.7rem; }
.decision-panel dd { font-size: 0.9rem; font-weight: 850; margin: var(--space-2xs) 0 0; overflow-wrap: anywhere; }
.formula-line { display: flex; flex-wrap: wrap; gap: var(--space-2xs); margin-top: var(--space-lg); }
.formula-line span,
.formula-line strong { background: var(--color-panel-2); border: 1px solid var(--color-rule); border-radius: 999px; color: var(--color-ink-soft); font-size: 0.68rem; padding: var(--space-xs); }
.formula-line strong { background: var(--color-accent-soft); border-color: color-mix(in oklch, var(--color-accent) 30%, var(--color-rule)); color: var(--color-accent); }
.formula-line b { align-self: center; color: var(--color-muted); }
.technical-score-list { display: grid; gap: var(--space-2xs); margin-top: var(--space-md); max-height: 11rem; overflow: auto; }
.technical-score-list > div { display: grid; font-size: 0.7rem; gap: var(--space-xs); grid-template-columns: minmax(0, 1fr) auto; padding: var(--space-xs); }
.technical-score-list > div.is-selected { background: var(--color-success-soft); }
.lesson-actions { display: grid; gap: var(--space-xs); grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: auto; padding-top: var(--space-lg); }
.lesson-actions button { background: var(--color-panel); border-color: var(--color-rule-strong); color: var(--color-ink); padding-inline: var(--space-xs); }
.lesson-actions button.is-primary { background: var(--color-accent); border-color: var(--color-accent); color: var(--color-accent-ink); }
.lesson-actions button:disabled { cursor: not-allowed; opacity: 0.45; }
.progress-label { color: var(--color-muted); font-size: 0.7rem; margin: var(--space-xs) 0 0; text-align: center; }

.lesson-mode button:focus-visible,
.lesson-actions button:focus-visible,
.stage-rail button:focus-visible,
.lesson-filters select:focus-visible {
    outline: 2px solid var(--color-warning);
    outline-offset: 2px;
}

.lesson-mode button:active,
.lesson-actions button:not(:disabled):active,
.stage-rail button:active {
    transform: translateY(1px);
}

.lesson-mode button:disabled,
.stage-rail button:disabled,
.lesson-filters select:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

@media (hover: hover) and (pointer: fine) {
    .chapter-strip button:hover,
    .score-row:hover,
    .stage-rail button:hover,
    .lesson-mode button:hover,
    .lesson-actions button:not(:disabled):hover {
        transform: translateY(-1px);
    }
}

@media (max-width: 80rem) {
    .lesson-shell {
        grid-template-columns: 8rem minmax(0, 1fr) 16rem;
    }

    .lesson-shell.is-map-stage {
        grid-template-columns: minmax(0, 1fr);
    }

    .route-learning-toolbar {
        grid-template-columns: minmax(10rem, 0.55fr) auto minmax(12rem, 1fr);
    }

    .route-micro-stepper {
        grid-column: 1 / -1;
        justify-content: end;
    }

    .lesson-stage,
    .decision-panel {
        padding: var(--space-md);
    }

    .score-visual {
        grid-template-columns: minmax(8rem, 0.65fr) auto minmax(14rem, 1.6fr);
    }

    .two-opt-explanation {
        grid-template-columns: minmax(14rem, 1.25fr) minmax(12rem, 0.8fr);
    }
}

@media (max-width: 64rem) {
    .lesson-toolbar {
        align-items: stretch;
        grid-template-columns: auto minmax(0, 1fr);
    }

    .lesson-summary {
        grid-column: 1 / -1;
        grid-row: 2;
        justify-content: start;
        margin: 0;
    }

    .lesson-filters {
        justify-content: end;
    }

    .lesson-shell {
        grid-template-columns: minmax(0, 1fr);
    }

    .lesson-shell.is-map-stage {
        grid-template-columns: minmax(0, 1fr);
    }

    .stage-rail {
        border-bottom: 1px solid var(--color-rule);
        border-inline-end: 0;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        grid-template-rows: auto;
        padding: var(--space-xs);
    }

    .stage-rail > p { display: none; }
    .stage-rail button { border-inline-start: 1px solid var(--color-rule); border-top: 0; text-align: center; }
    .stage-rail button:first-of-type { border-inline-start: 0; }
    .stage-rail button[aria-current="step"] { box-shadow: inset 0 -3px 0 var(--color-accent); }

    .decision-panel {
        display: grid;
        gap: var(--space-sm);
        grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    }

    .decision-panel > h3 { margin: 0; }
    .decision-panel dl { grid-column: 2; grid-row: 1 / span 3; }
    .decision-kicker { grid-column: 1; }
    .lesson-actions { margin-top: 0; padding-top: var(--space-sm); }
    .progress-label { grid-column: 1; }
    .formula-line,
    .technical-score-list { grid-column: 1; margin-top: 0; }

    .nearest-explanation { grid-template-columns: minmax(9rem, 0.65fr) auto minmax(16rem, 1.6fr); }

    .route-stage-result {
        align-items: stretch;
        grid-template-columns: minmax(0, 1fr);
    }

    .route-stage-actions {
        min-width: 0;
    }

    .route-learning-toolbar {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .focus-route-heading,
    .route-micro-stepper {
        grid-column: 1 / -1;
    }
}

@media (max-width: 72rem) {
    .route-viz-toolbar,
    .route-viz-workbench,
    .map-panel-head {
        grid-template-columns: minmax(0, 1fr);
    }

    .route-viz-filters,
    .route-viz-actions {
        justify-content: start;
    }

    .chapter-strip {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 44rem) {
    .route-viz {
        padding: var(--space-sm);
    }

    .route-viz-header,
    .route-viz-actions,
    .route-viz-filters {
        align-items: stretch;
        flex-direction: column;
    }

    .route-viz-link,
    .route-viz-actions button {
        width: 100%;
    }

    .route-viz-overview,
    .chapter-strip,
    .fact-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .route-viz-mode {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .route-viz-mode button {
        padding-inline: var(--space-sm);
    }

    .route-viz-filters label {
        min-width: 0;
    }

    .map-canvas svg {
        height: 23rem;
        min-height: 23rem;
    }

    .school-label,
    .fleet-label,
    .active-student-label {
        font-size: 15px;
    }

    .lesson-toolbar,
    .lesson-filters,
    .lesson-stage-head,
    .filter-visual,
    .score-visual,
    .decision-panel {
        grid-template-columns: minmax(0, 1fr);
    }

    .lesson-mode {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .lesson-filters,
    .lesson-summary {
        grid-column: 1;
        justify-content: start;
    }

    .lesson-filters {
        display: grid;
    }

    .lesson-filters select { min-width: 0; width: 100%; }

    .lesson-summary { flex-wrap: wrap; }

    .stage-rail {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .lesson-shell.is-map-stage .stage-rail {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .stage-rail button:nth-of-type(4) { border-inline-start: 0; }

    .lesson-stage { padding: var(--space-sm); }
    .lesson-stage-head { gap: var(--space-xs); }
    .stage-number { font-size: 1.8rem; }
    .teaching-canvas { min-height: 0; padding-block: var(--space-lg); }

    .filter-visual { gap: var(--space-md); }
    .input-pile { grid-template-columns: repeat(6, 1rem); }
    .input-pile > span { height: 1rem; width: 1rem; }

    .priority-head { align-items: start; flex-direction: column; gap: var(--space-xs); }
    .priority-list li { grid-template-columns: 1.75rem minmax(0, 1fr) 4.5rem; }
    .priority-list li > b { height: 1.75rem; }

    .choice-arrow { flex-direction: row; font-size: 1.2rem; gap: var(--space-xs); }

    .before-after-labels { display: none; }
    .load-comparison { grid-template-columns: minmax(0, 1fr) 1.5rem minmax(0, 1fr); }
    .load-name { grid-column: 1 / -1; }
    .move-result { align-items: start; flex-direction: column; gap: var(--space-xs); }

    .route-learning-toolbar { align-items: stretch; grid-template-columns: minmax(0, 1fr); }
    .focus-route-heading,
    .route-micro-stepper { grid-column: 1; }
    .route-method-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .teaching-trip-control { justify-self: stretch; max-width: none; }
    .route-micro-stepper { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); justify-content: stretch; }
    .route-micro-stepper strong { min-width: 0; }
    .route-micro-stepper button { min-width: 0; padding-inline: var(--space-xs); }
    .nearest-explanation { grid-template-columns: minmax(0, 1fr); }
    .nearest-arrow { transform: rotate(90deg); }
    .two-opt-explanation { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .two-opt-explanation > div { border-inline-start: 0; border-top: 1px solid var(--color-rule); padding: var(--space-xs) 0 0; }
    .two-opt-explanation > div:first-child { border-top: 0; grid-column: 1 / -1; padding-top: 0; }
    .two-opt-edge-summary { grid-template-columns: minmax(0, 1fr); }
    .sequence-comparison { grid-template-columns: minmax(0, 1fr); }
    .sequence-comparison > div { align-items: start; grid-template-columns: minmax(0, 1fr); }

    .route-map,
    .route-map-canvas { min-height: 19rem; }
    .route-map-canvas { height: 19rem; }
    .lesson-shell.is-map-stage .route-map,
    .lesson-shell.is-map-stage .route-map-canvas { min-height: 22rem; }
    .lesson-shell.is-map-stage .route-map-canvas { height: clamp(22rem, 55dvh, 30rem); }
    .route-step-inspector { border-inline: 0; border-radius: 0; bottom: auto; box-shadow: none; left: auto; max-width: none; position: relative; width: 100%; }
    .map-key { inset-inline-end: var(--space-xs); inset-inline-start: 3.5rem; max-width: none; }
    .map-camera-actions { background: var(--color-panel); border-top: 1px solid var(--color-rule); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); inset: auto; padding: var(--space-xs); position: relative; }
    .map-camera-actions button { min-height: 2.75rem; }

    .decision-panel dl {
        grid-column: 1;
        grid-row: auto;
    }

    .lesson-actions { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .lesson-actions button { min-width: 0; padding-inline: var(--space-2xs); }
    .progress-label { grid-column: 1; }
    .focus-stage-footer { align-items: stretch; grid-template-columns: minmax(0, 1fr); }
    .focus-stage-footer .route-stage-actions { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@media (max-width: 22rem) {
    .route-method-tabs,
    .improve-method-tabs,
    .map-camera-actions { grid-template-columns: minmax(0, 1fr); }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 150ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 150ms !important;
    }
}
</style>
