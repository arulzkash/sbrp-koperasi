<script setup>
import { ref, computed, onMounted, watch } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import "leaflet-rotatedmarker";

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
let vehicleAnimations = [];
let renderVersion = 0;

const activeFleetId = ref(null);
const animationEnabled = ref(false);

const routeCache = new Map();

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

const vehicleIcon = L.icon({
    iconUrl: "https://cdn-icons-png.flaticon.com/512/744/744465.png",
    iconSize: [32, 32],
    iconAnchor: [16, 16],
});

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

const routeReadyStudents = computed(() => {
    if (viewMode.value === "morning") {
        return props.students.filter((student) => {
            return (
                student.payment_status === "paid" &&
                ["full", "pickup_only"].includes(student.service_type)
            );
        });
    }

    return props.students.filter((student) => {
        return (
            student.payment_status === "paid" &&
            ["full", "dropoff_only"].includes(student.service_type) &&
            student.session_out === selectedSession.value
        );
    });
});

const routeReadyStudentsCount = computed(() => {
    return routeReadyStudents.value.length;
});

const routedStudentsCount = computed(() => {
    return routeReadyStudents.value.filter((student) => {
        return viewMode.value === "morning"
            ? student.morning_fleet_id !== null
            : student.afternoon_fleet_id !== null;
    }).length;
});

const unroutedStudentsCount = computed(() => {
    return Math.max(
        routeReadyStudentsCount.value - routedStudentsCount.value,
        0,
    );
});

const hasRouteReadyStudents = computed(() => {
    return routeReadyStudentsCount.value > 0;
});

const hasRoutedStudents = computed(() => {
    return routedStudentsCount.value > 0;
});

const activeFleetsCount = computed(() => {
    return sidebarData.value.length;
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

    renderVersion++;
    const currentRender = renderVersion;

    vehicleAnimations.forEach((i) => clearInterval(i));
    vehicleAnimations = [];

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

    const hasActive = activeFleetId.value !== null;

    sidebarData.value.forEach((fleet, index) => {
        if (hasActive && activeFleetId.value !== fleet.id) {
            return;
        }

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
                            width:${hasActive ? 16 : 10}px;
                            height:${hasActive ? 16 : 10}px;
                            border:2px solid black;">
                        </div>`,
                        className: "",
                        iconSize: hasActive ? [16, 16] : [10, 10],
                        iconAnchor: hasActive ? [8, 8] : [5, 5],
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

            const order =
                viewMode.value === "morning"
                    ? student.morning_route_order
                    : student.afternoon_route_order;

            routeCoords.push(coord);
            bounds.push(coord);

            const iconHtml = hasActive
                ? `<div style="
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
                    </div>`
                : `<div style="
                        background:${fleetColor};
                        width:12px;
                        height:12px;
                        border-radius:50%;
                        border:2px solid white;
                        box-shadow:0 0 2px rgba(0,0,0,0.4);
                        opacity:0.9;
                    "></div>`;

            const marker = L.marker(coord, {
                icon: L.divIcon({
                    html: iconHtml,
                    className:
                        "transition-all duration-300 hover:scale-125 hover:z-50",
                    iconSize: hasActive ? [26, 26] : [12, 12],
                    iconAnchor: hasActive ? [13, 13] : [6, 6],
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
            drawRouteWithOSRM(
                routeCoords,
                fleetColor,
                index,
                currentRender,
                fleet.id,
            );
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
        "/admin/test-dashboard/generate",
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isGenerating.value = false;
            },
        },
    );
};

const drawRouteWithOSRM = async (
    coords,
    color,
    fleetindex = 0,
    renderId,
    fleetId,
) => {
    if (coords.length < 2) return;

    const coordString = coords.map((c) => `${c[1]},${c[0]}`).join(";");
    const hasActive = activeFleetId.value !== null;
    const isActive = activeFleetId.value === fleetId;

    const drawPolyline = (routePoints) => {
        L.polyline(routePoints, {
            color: color,
            weight: hasActive ? 5 : 2,
            opacity: hasActive ? 1 : 0.4,
            dashArray: null,
        }).addTo(markersLayer);

        if (animationEnabled.value && isActive) {
            animateVehicle(routePoints);
        }
    };

    // --- MENCEGAH RATE LIMIT API OSRM ---
    // Request ke public OSRM dibatasi. Jika me-render 20 armada sekaligus akan kena block (ERR_FAILED).
    // Jadi di mode overview, kita tidak menggambar garis rute sama sekali (hanya titik-titik saja).
    // OSRM HANYA di-fetch saat user fokus ke 1 armada tertentu (!isActive = false).
    if (!isActive) {
        return;
    }

    // CACHE
    if (routeCache.has(coordString)) {
        if (renderId !== renderVersion) return;

        const cachedRoute = routeCache.get(coordString);
        drawPolyline(cachedRoute);
        return;
    }

    const url = `https://router.project-osrm.org/route/v1/driving/${coordString}?overview=full&geometries=geojson`;

    try {
        const res = await fetch(url);
        const data = await res.json();

        if (renderId !== renderVersion) return;
        if (!data.routes || !data.routes.length) return;

        const route = data.routes[0].geometry.coordinates.map((c) => [
            c[1],
            c[0],
        ]);

        routeCache.set(coordString, route);
        drawPolyline(route);
    } catch (err) {
        if (renderId !== renderVersion) return;

        console.error("OSRM error", err);

        L.polyline(coords, {
            color: color,
            weight: hasActive ? 5 : 2,
            opacity: hasActive ? 1 : 0.4,
            dashArray: "5,10",
        }).addTo(markersLayer);
    }
};

const animateVehicle = (route) => {
    if (!route || route.length < 2) return;

    const marker = L.marker(route[0], {
        icon: vehicleIcon,
        rotationAngle: 0,
    }).addTo(markersLayer);

    let i = 0;
    const myRenderVersion = renderVersion;

    const interval = setInterval(() => {
        if (myRenderVersion !== renderVersion) {
            clearInterval(interval);
            return;
        }

        if (!map || !markersLayer.hasLayer(marker)) {
            clearInterval(interval);
            return;
        }

        if (i >= route.length - 1) {
            clearInterval(interval);
            return;
        }

        const current = route[i];
        const next = route[i + 1];

        const dx = next[1] - current[1];
        const dy = next[0] - current[0];

        // Hitung sudut berdasarkan koordinat (X = bujur (lng) diff, Y = lintang (lat) diff)
        // atan2(dy, dx) mereturn arah standar (Timur = 0, Utara = 90)
        // Icon mobil biasanya default-nya hadap atas (Utara).
        // Jika aslinya tegak (+) dan mau dibikin rebahan (-), kita sesuaikan base anglenya (+90 atau -90 derajat)
        let angle = (Math.atan2(dy, dx) * 180) / Math.PI;
        // Kurangi 90 supaya sejajar horizontal kalau default mobilnya vertikal
        angle = angle - 90;

        marker.setRotationAngle(angle);
        marker.setLatLng(next);

        i++;
    }, 80);

    vehicleAnimations.push(interval);
};

const focusFleet = (fleetId) => {
    vehicleAnimations.forEach((i) => clearInterval(i));
    vehicleAnimations = [];
    animationEnabled.value = false;

    activeFleetId.value = activeFleetId.value === fleetId ? null : fleetId;

    renderMap();
};

const startAnimation = () => {
    if (!activeFleetId.value) return;

    vehicleAnimations.forEach((i) => clearInterval(i));
    vehicleAnimations = [];

    animationEnabled.value = true;

    renderMap();
};

// Pantau perubahan pada props data atau filter dropdown
watch(
    [() => props.students, viewMode, selectedSession],
    (
        [studentsNow, modeNow, sessionNow],
        [studentsOld, modeOld, sessionOld],
    ) => {
        if (modeNow !== modeOld || sessionNow !== sessionOld) {
            activeFleetId.value = null;
            animationEnabled.value = false;
            vehicleAnimations.forEach((i) => clearInterval(i));
            vehicleAnimations = [];
        }

        renderMap();
    },
    { deep: true },
);

onMounted(() => {
    initMap();
});
</script>

<template>
    <Head title="Monitoring Armada" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Ruang Kendali Operasional Armada
            </h2>
        </template>

        <div class="p-6 max-w-7xl mx-auto">
            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <p class="text-xs font-bold uppercase text-blue-700">
                        Siswa Siap Dirutekan
                    </p>
                    <p class="mt-1 text-2xl font-bold text-blue-900">
                        {{ routeReadyStudentsCount }}
                    </p>
                    <p class="mt-1 text-[11px] text-blue-700">
                        Sudah lunas dan sesuai filter aktif
                    </p>
                </div>

                <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                    <p class="text-xs font-bold uppercase text-green-700">
                        Sudah Terpetakan
                    </p>
                    <p class="mt-1 text-2xl font-bold text-green-900">
                        {{ routedStudentsCount }}
                    </p>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs font-bold uppercase text-amber-700">
                        Belum Masuk Rute
                    </p>
                    <p class="mt-1 text-2xl font-bold text-amber-900">
                        {{ unroutedStudentsCount }}
                    </p>
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase text-slate-700">
                        Armada Aktif Di Tampilan
                    </p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">
                        {{ activeFleetsCount }}
                    </p>
                </div>
            </div>

            <div
                class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded flex flex-col md:flex-row items-center justify-between gap-4"
            >
                <!-- LEFT SIDE -->
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

                <!-- RIGHT SIDE BUTTON GROUP -->
                <div class="flex flex-col items-end gap-2">
                    <div class="flex items-center gap-3">
                        <button
                            v-if="activeFleetId"
                            @click="startAnimation"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow"
                        >
                            ▶ Simulasi Armada
                        </button>

                        <button
                            @click="generateRoute"
                            :disabled="isGenerating"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow disabled:opacity-50 flex items-center gap-2"
                        >
                            <span v-if="!isGenerating"
                                >🚀 Generate Rute Baru</span
                            >
                            <span v-else>Menghitung Algoritma...</span>
                        </button>
                    </div>

                    <p
                        class="text-[11px] text-right text-blue-700 max-w-xs leading-4"
                    >
                        Generate memproses siswa yang sudah lunas dan sesuai
                        mode layanan serta sesi pulang.
                    </p>
                </div>
            </div>

            <div class="flex gap-6">
                <div class="w-2/3">
                    <div
                        id="map"
                        class="h-[600px] rounded shadow z-0 border border-gray-300"
                    ></div>
                </div>

                <div
                    class="w-1/3 bg-white p-4 rounded shadow border border-gray-200 max-h-[600px] overflow-y-auto pr-1"
                >
                    <!-- HEADER -->
                    <h2 class="font-semibold text-gray-800 text-lg mb-1">
                        Penugasan Armada
                    </h2>

                    <p
                        class="text-xs text-gray-500 mb-4 pb-3 border-b leading-5"
                    >
                        <span v-if="viewMode === 'morning'">
                            Menampilkan siswa siap dirutekan untuk rute pagi:
                            layanan PP dan berangkat saja.
                        </span>

                        <span v-else>
                            Menampilkan siswa siap dirutekan untuk rute pulang
                            sesi
                            {{ selectedSession.substring(0, 5) }}: layanan PP
                            dan pulang saja.
                        </span>
                    </p>

                    <!-- EMPTY STATE -->
                    <div
                        v-if="sidebarData.length === 0"
                        class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center"
                    >
                        <p class="text-sm font-semibold text-gray-700">
                            Belum ada armada yang tampil pada filter ini.
                        </p>

                        <p
                            v-if="!hasRouteReadyStudents"
                            class="mt-2 text-xs text-gray-500"
                        >
                            Tidak ada siswa siap dirutekan pada mode atau sesi
                            yang sedang dipilih.
                        </p>

                        <p
                            v-else-if="!hasRoutedStudents"
                            class="mt-2 text-xs text-gray-500"
                        >
                            Ada siswa siap dirutekan, tetapi rute belum
                            digenerate atau belum tersimpan.
                        </p>

                        <p v-else class="mt-2 text-xs text-gray-500">
                            Coba ubah mode, sesi, atau generate ulang rute.
                        </p>
                    </div>

                    <!-- FLEET CARD -->
                    <div
                        v-for="(fleet, index) in sidebarData"
                        :key="fleet.id"
                        @click="focusFleet(fleet.id)"
                        class="mb-4 cursor-pointer rounded-lg border p-3 transition-all duration-200"
                        :class="{
                            'bg-yellow-50 border-yellow-300 shadow-sm':
                                activeFleetId === fleet.id,

                            'bg-white border-gray-200 hover:bg-gray-50 hover:border-gray-300 hover:shadow-sm':
                                activeFleetId !== fleet.id,
                        }"
                    >
                        <!-- HEADER -->
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span
                                    class="w-3 h-3 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            colors[index % colors.length],
                                    }"
                                ></span>

                                <h3 class="font-semibold text-gray-800">
                                    🚗 {{ fleet.name }}
                                </h3>
                            </div>

                            <span class="text-xs text-gray-400 font-medium">
                                {{ fleet.assigned_students.length }}/{{
                                    fleet.capacity
                                }}
                            </span>
                        </div>

                        <!-- CAPACITY BAR -->
                        <div class="ml-5 mb-3">
                            <div class="text-xs text-gray-500 mb-1">
                                Kapasitas Terpakai
                            </div>

                            <div
                                class="w-full bg-gray-100 rounded h-2 overflow-hidden"
                            >
                                <div
                                    class="h-2 rounded transition-all"
                                    :style="{
                                        width:
                                            (fleet.assigned_students.length /
                                                fleet.capacity) *
                                                100 +
                                            '%',
                                        backgroundColor:
                                            colors[index % colors.length],
                                    }"
                                ></div>
                            </div>
                        </div>

                        <!-- STUDENT LIST -->
                        <ul
                            class="list-none pl-6 text-sm space-y-1 relative border-l-2 border-gray-100 ml-2"
                        >
                            <li
                                v-for="student in fleet.assigned_students"
                                :key="student.id"
                                class="relative pl-4 py-1 rounded"
                            >
                                <!-- NUMBER -->
                                <span
                                    class="absolute -left-[10px] top-1 bg-white border-2 rounded-full w-5 h-5 flex items-center justify-center text-[10px] font-bold shadow-sm"
                                    :style="{
                                        borderColor:
                                            colors[index % colors.length],
                                        color: colors[index % colors.length],
                                    }"
                                >
                                    {{
                                        viewMode === "morning"
                                            ? student.morning_route_order
                                            : student.afternoon_route_order
                                    }}
                                </span>

                                <!-- NAME -->
                                <p class="font-medium text-gray-700">
                                    {{ student.name }}
                                </p>

                                <!-- META -->
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
    </AuthenticatedLayout>
</template>
