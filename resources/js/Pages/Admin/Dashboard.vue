<script setup>
import { ref, onMounted, watch } from "vue";
import { router } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

// Terima data dari Laravel Controller
const props = defineProps({
    routesData: Array,
});

// State untuk checkbox
const includeUnpaid = ref(false);
const isGenerating = ref(false);

let map = null;

// Fungsi untuk me-refresh data saat checkbox dicentang
const reloadRoute = () => {
    router.get(
        "/admin/dashboard", // Pastikan URL ini sesuai dengan routes web.php kamu ya
        { include_unpaid: includeUnpaid.value ? 1 : 0 },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
};

// Daftar warna untuk membedakan mobil di peta
const colors = ["red", "blue", "green", "purple", "orange", "darkred"];

// Fungsi inisialisasi peta Leaflet
const initMap = () => {
    if (map) map.remove(); // Bersihkan peta lama jika ada

    // Set view awal ke koordinat sekolah (Lembang)
    map = L.map("map").setView([-6.815348, 107.616659], 13);

    // Load gambar peta dari OpenStreetMap (Gratis!)
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
    }).addTo(map);

    // Tambahkan Marker Sekolah (Titik Tujuan)
    L.circleMarker([-6.815348, 107.616659], {
        color: "black",
        radius: 10,
        fillOpacity: 1,
    })
        .addTo(map)
        .bindPopup("<b>SEKOLAH (Tujuan)</b>");

    // Looping data armada dan tambahkan titik serta garis rute
    props.routesData.forEach((fleet, index) => {
        const fleetColor = colors[index % colors.length];

        // Buat array untuk menampung urutan koordinat jalur mobil ini
        let routeCoordinates = [];

        // 1. Titik Awal: Pool / Garasi Armada
        if (fleet.base_lat && fleet.base_lng) {
            routeCoordinates.push([fleet.base_lat, fleet.base_lng]);
            
            // Bikin marker kotak buat Garasi (opsional biar beda sama siswa)
            L.marker([fleet.base_lat, fleet.base_lng], {
                icon: L.divIcon({
                    html: `<div style="background-color: ${fleetColor}; width: 14px; height: 14px; border: 2px solid black;"></div>`,
                    className: ''
                })
            }).addTo(map).bindPopup(`<b>POOL: ${fleet.fleet_name}</b>`);
        }

        // 2. Titik-Titik Siswa sesuai route_order
        fleet.students.forEach((student) => {
            // Masukkan koordinat siswa ke jalur rute
            routeCoordinates.push([student.lat, student.lng]);

            L.circleMarker([student.lat, student.lng], {
                color: fleetColor,
                fillColor: fleetColor,
                fillOpacity: 0.7,
                radius: 8,
            })
                .addTo(map)
                .bindPopup(
                    `<b>${student.name}</b><br>Ikut: ${fleet.fleet_name}<br>Urutan: ${student.route_order}<br>Jarak dr Pool: ${student.distance_from_base}`
                );
        });

        // 3. Titik Akhir: Sekolah
        // Setelah jemput semua anak, mobil pergi ke sekolah
        routeCoordinates.push([-6.815348, 107.616659]);

        // 4. GAMBAR GARIS RUTENYA (Polyline)
        // Ini yang bikin skripsimu terlihat punya algoritma routing sungguhan!
        if (routeCoordinates.length > 1) {
            L.polyline(routeCoordinates, {
                color: fleetColor,
                weight: 3, // Ketebalan garis
                opacity: 0.8,
                dashArray: '5, 10' // Bikin garisnya putus-putus biar elegan
            }).addTo(map);
        }
    });
};

// Fungsi aksi klik tombol
const generateRoute = () => {
    isGenerating.value = true;
    router.post(
        "/admin/test-route/generate",
        {
            include_unpaid: includeUnpaid.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                isGenerating.value = false;
            },
        }
    );
};

// Pantau perubahan pada props.routesData
watch(
    () => props.routesData,
    (newData) => {
        initMap();
    },
    { deep: true }
);
// ----------------------------------

// Jalankan peta setelah halaman selesai dimuat
onMounted(() => {
    initMap();
});
</script>

<template>
    <div class="p-6 max-w-7xl mx-auto">
        <h1 class="text-2xl font-bold mb-4">Prototipe Optimasi Rute (SBRP)</h1>

        <div
            class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded flex items-center justify-between gap-3"
        >
            <div class="flex items-center gap-3">
                <input
                    type="checkbox"
                    id="unpaid_filter"
                    v-model="includeUnpaid"
                    class="w-5 h-5"
                />
                <label for="unpaid_filter" class="font-semibold cursor-pointer">
                    Sertakan Siswa Nunggak (Unpaid)?
                </label>
            </div>

            <button
                @click="generateRoute"
                :disabled="isGenerating"
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow disabled:opacity-50"
            >
                {{
                    isGenerating
                        ? "Menghitung Rute..."
                        : "🚀 Generate Rute Baru"
                }}
            </button>
        </div>

        <div class="flex gap-6">
            <div class="w-2/3">
                <div id="map" class="h-[500px] rounded shadow z-0"></div>
            </div>

            <div
                class="w-1/3 bg-white p-4 rounded shadow max-h-[500px] overflow-y-auto"
            >
                <h2 class="font-bold text-lg mb-4">Hasil Alokasi Armada</h2>

                <div
                    v-for="(fleet, index) in routesData"
                    :key="index"
                    class="mb-4 border-b pb-4"
                >
                    <h3 class="font-semibold text-blue-600">
                        🚗 {{ fleet.fleet_name }}
                    </h3>
                    <p class="text-sm text-gray-600 mb-2">
                        Kapasitas: {{ fleet.current_load }} /
                        {{ fleet.capacity }} Kursi
                    </p>

                    <ul class="list-disc pl-5 text-sm">
                        <li v-for="student in fleet.students" :key="student.id">
                            {{ student.name }} ({{
                                student.distance_from_base
                            }}
                            KM)
                        </li>
                        <li
                            v-if="fleet.students.length === 0"
                            class="text-gray-400 italic"
                        >
                            Kosong
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
