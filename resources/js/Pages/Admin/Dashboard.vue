<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { router } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

// Terima data dari Laravel Controller
const props = defineProps({
    fleets: Array,
    students: Array,
});

const isGenerating = ref(false);

// STATE FILTER
const viewMode = ref("morning"); // Pilihan: 'morning' / 'afternoon'
const selectedSession = ref("13:00:00"); // Sesuai format database time (H:i:s)

let map = null;
let markersLayer = null; // Layer khusus untuk menampung marker agar mudah dihapus

const SCHOOL_COORD = [-6.826864390637824, 107.63886429303408];

// Daftar warna armada
const colors = [
    "#ef4444",
    "#3b82f6",
    "#22c55e",
    "#a855f7",
    "#f97316",
    "#991b1b",
];

// 1. FILTER DATA SISWA (Real-time berdasarkan dropdown)
const filteredStudents = computed(() => {
    if (viewMode.value === "morning") {
        return props.students
            .filter((s) => s.morning_fleet_id !== null)
            .sort((a, b) => a.morning_route_order - b.morning_route_order);
    } else {
        return props.students
            .filter(
                (s) =>
                    s.afternoon_fleet_id !== null &&
                    s.session_out === selectedSession.value,
            )
            .sort((a, b) => a.afternoon_route_order - b.afternoon_route_order);
    }
});

// 2. KELOMPOKKAN KE DALAM ARMADA (Untuk Sidebar & Pembuatan Garis Peta)
const sidebarData = computed(() => {
    return props.fleets
        .map((fleet) => {
            // Cari siswa yang masuk ke armada ini pada sesi yang sedang dipilih
            const fleetStudents = filteredStudents.value.filter((s) => {
                return viewMode.value === "morning"
                    ? s.morning_fleet_id === fleet.id
                    : s.afternoon_fleet_id === fleet.id;
            });

            return {
                ...fleet,
                assigned_students: fleetStudents,
            };
        })
        .filter((f) => f.assigned_students.length > 0); // Sembunyikan armada yang nganggur di sesi ini
});

// 3. FUNGSI RENDER PETA
const renderMap = () => {

    if (!map) return;

    markersLayer.clearLayers();

    const bounds = [];

    // MARKER SEKOLAH
    L.circleMarker(SCHOOL_COORD, {
        color: "black",
        radius: 10,
        fillOpacity: 1,
    })
        .addTo(markersLayer)
        .bindPopup("<b>SEKOLAH</b>");

    bounds.push(SCHOOL_COORD);

    sidebarData.value.forEach((fleet, index) => {

        const fleetColor = colors[index % colors.length];

        const routeCoords = [];

        // ======================
        // TITIK AWAL
        // ======================

        if (viewMode.value === "morning") {

            if (fleet.base_latitude && fleet.base_longitude) {

                const base = [fleet.base_latitude, fleet.base_longitude];

                routeCoords.push(base);
                bounds.push(base);

                L.marker(base, {
                    icon: L.divIcon({
                        html: `<div style="
                            background:${fleetColor};
                            width:14px;
                            height:14px;
                            border:2px solid black;">
                        </div>`,
                        className: "",
                    }),
                })
                    .addTo(markersLayer)
                    .bindPopup(`<b>POOL ${fleet.name}</b>`);
            }

        } else {

            routeCoords.push(SCHOOL_COORD);
        }


        // ======================
        // SISWA
        // ======================

        fleet.assigned_students.forEach((student) => {

            const coord = [student.latitude, student.longitude];

            const order = viewMode.value === "morning"
                ? student.morning_route_order
                : student.afternoon_route_order;

            routeCoords.push(coord);
            bounds.push(coord);

            const marker = L.marker(coord, {
                icon: L.divIcon({
                    html: `
                    <div style="
                        background:${fleetColor};
                        width:26px;
                        height:26px;
                        border-radius:50%;
                        display:flex;
                        align-items:center;
                        justify-content:center;
                        color:white;
                        font-weight:bold;
                        font-size:12px;
                        border:2px solid white;
                        box-shadow:0 0 4px rgba(0,0,0,0.4);
                    ">
                        ${order}
                    </div>
                    `,
                    className: "",
                }),
            });

            marker.addTo(markersLayer).bindPopup(`
                <b>${student.name}</b><br>
                Armada: ${fleet.name}<br>
                Urutan: ${order}
            `);

        });


        // ======================
        // TITIK AKHIR
        // ======================

        if (viewMode.value === "morning") {

            routeCoords.push(SCHOOL_COORD);
        }


        // ======================
        // ROUTE OSRM
        // ======================

        if (routeCoords.length > 1) {

            drawRouteWithOSRM(routeCoords, fleetColor, index);

        }

    });


    // ======================
    // AUTO ZOOM
    // ======================

    if (bounds.length > 0) {

        map.fitBounds(bounds, {
            padding: [40, 40],
        });

    }

};

const initMap = () => {
    if (map) map.remove();

    map = L.map("map").setView(SCHOOL_COORD, 13);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);
    renderMap(); // Panggil pertama kali
};

const generateRoute = () => {
    isGenerating.value = true;
    router.post(
        "/admin/test-route/generate",
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isGenerating.value = false;
            },
        },
    );
};

const drawRouteWithOSRM = async (coords, color, fleetindex = 0) => {
    if (coords.length < 2) return;

    const coordString = coords.map((c) => `${c[1]},${c[0]}`).join(";");

    const url = `https://router.project-osrm.org/route/v1/driving/${coordString}?overview=full&geometries=geojson`;

    try {
        const res = await fetch(url);
        const data = await res.json();

        const route = data.routes[0].geometry.coordinates.map((c) => [
            c[1],
            c[0],
        ]);

        L.polyline(route, {
            color: color,
            weight: 5,
            opacity: 0.7,
            dashArray: fleetindex % 2 ? "8,6" : null,
        }).addTo(markersLayer);
    } catch (err) {
        console.error("OSRM error", err);

        // fallback ke garis lurus kalau gagal
        L.polyline(coords, {
            color: color,
            weight: 3,
            dashArray: "5,10",
        }).addTo(markersLayer);
    }
};

// Pantau perubahan pada props data atau filter dropdown
watch(
    [() => props.students, viewMode, selectedSession],
    () => {
        renderMap();
    },
    { deep: true },
);

onMounted(() => {
    initMap();
});
</script>

<template>
    <div class="p-6 max-w-7xl mx-auto">
        <h1 class="text-2xl font-bold mb-4">
            Ruang Kendali Operasional Armada
        </h1>

        <div
            class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded flex flex-col md:flex-row items-center justify-between gap-4"
        >
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex flex-col">
                    <label
                        class="text-xs font-bold text-gray-500 mb-1 uppercase"
                        >Mode Rute</label
                    >
                    <select
                        v-model="viewMode"
                        class="border-gray-300 rounded-md shadow-sm font-semibold"
                    >
                        <option value="morning">
                            ☀️ Rute Pagi (Ke Sekolah)
                        </option>
                        <option value="afternoon">
                            🌙 Rute Siang/Sore (Pulang)
                        </option>
                    </select>
                </div>

                <div class="flex flex-col" v-if="viewMode === 'afternoon'">
                    <label
                        class="text-xs font-bold text-gray-500 mb-1 uppercase"
                        >Sesi Jam Pulang</label
                    >
                    <select
                        v-model="selectedSession"
                        class="border-gray-300 rounded-md shadow-sm font-semibold"
                    >
                        <option value="13:00:00">Sesi 1 (13:00 WIB)</option>
                        <option value="13:30:00">Sesi 2 (13:30 WIB)</option>
                        <option value="14:30:00">Sesi 3 (14:30 WIB)</option>
                        <option value="15:30:00">Sesi 4 (15:30 WIB)</option>
                        <option value="15:45:00">Sesi 5 (15:45 WIB)</option>
                    </select>
                </div>
            </div>

            <button
                @click="generateRoute"
                :disabled="isGenerating"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow disabled:opacity-50 flex items-center gap-2"
            >
                <span v-if="!isGenerating">🚀 Generate Rute Baru</span>
                <span v-else>Menghitung Algoritma...</span>
            </button>
        </div>

        <div class="flex gap-6">
            <div class="w-2/3">
                <div
                    id="map"
                    class="h-[600px] rounded shadow z-0 border border-gray-300"
                ></div>
            </div>

            <div
                class="w-1/3 bg-white p-4 rounded shadow border border-gray-200 max-h-[600px] overflow-y-auto"
            >
                <h2 class="font-bold text-lg mb-2">Penugasan Armada</h2>
                <p class="text-sm text-gray-500 mb-4 pb-2 border-b">
                    <span v-if="viewMode === 'morning'"
                        >Menampilkan rute jemputan pagi (serentak).</span
                    >
                    <span v-else
                        >Menampilkan rute pulang untuk Sesi
                        {{ selectedSession.substring(0, 5) }}.</span
                    >
                </p>

                <div
                    v-if="sidebarData.length === 0"
                    class="text-center text-gray-400 py-8 italic"
                >
                    Belum ada armada yang ditugaskan untuk sesi ini.
                </div>

                <div
                    v-for="(fleet, index) in sidebarData"
                    :key="index"
                    class="mb-6"
                >
                    <div class="flex items-center gap-2 mb-2">
                        <span
                            class="w-4 h-4 rounded-full"
                            :style="{
                                backgroundColor: colors[index % colors.length],
                            }"
                        ></span>
                        <h3 class="font-bold text-gray-800">
                            🚗 {{ fleet.name }}
                        </h3>
                    </div>

                    <p class="text-xs text-gray-500 mb-2 ml-6">
                        Kapasitas Terpakai:
                        <b class="text-gray-700">{{
                            fleet.assigned_students.length
                        }}</b>
                        / {{ fleet.capacity }} Kursi
                    </p>

                    <ul
                        class="list-none pl-6 text-sm space-y-2 relative border-l-2 border-gray-100 ml-2"
                    >
                        <li
                            v-for="student in fleet.assigned_students"
                            :key="student.id"
                            class="relative pl-4"
                        >
                            <span
                                class="absolute -left-[9px] top-1 bg-white border-2 rounded-full w-4 h-4 flex items-center justify-center text-[10px] font-bold"
                                :style="{
                                    borderColor: colors[index % colors.length],
                                    color: colors[index % colors.length],
                                }"
                            >
                                {{
                                    viewMode === "morning"
                                        ? student.morning_route_order
                                        : student.afternoon_route_order
                                }}
                            </span>

                            <p class="font-semibold text-gray-700">
                                {{ student.name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                Kls: {{ student.class_room || "-" }} | Sesi:
                                {{ student.session_out.substring(0, 5) }}
                            </p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
