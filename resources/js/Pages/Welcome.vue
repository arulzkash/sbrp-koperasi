<script setup>
import { ref, onMounted } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const SCHOOL_LAT = -6.826864390637824;
const SCHOOL_LNG = 107.63886429303408;

const userLat = ref(null);
const userLng = ref(null);
const distanceKm = ref(0);
const estimatedPrice = ref(0);
const estimatedPriceOneWay = ref(0);

const BASE_PRICE = 200000;
const PRICE_PER_KM = 50000;

// State untuk Pencarian Alamat
const searchQuery = ref("");
const isSearching = ref(false);

let map = null;
let userMarker = null;
let routeLine = null;

const calculateDistance = (lat1, lon1, lat2, lon2) => {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLon / 2) *
            Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
};

// Fungsi Utama untuk Update Titik, Jarak, dan Harga
const updateLocation = async (lat, lng) => {
    userLat.value = lat;
    userLng.value = lng;

    if (!userMarker) {
        // Bikin marker dengan fitur DRAGGABLE (Bisa digeser)
        userMarker = L.marker([lat, lng], { draggable: true })
            .addTo(map)
            .bindPopup("<b>Geser pin ini ke depan rumah Anda</b>")
            .openPopup();

        // Event saat marker selesai digeser
        userMarker.on("dragend", function (e) {
            const position = userMarker.getLatLng();
            updateLocation(position.lat, position.lng);
            map.panTo(position); // Peta ngikutin pin
        });
    } else {
        userMarker.setLatLng([lat, lng]);
    }

    if (routeLine) map.removeLayer(routeLine);

    try {
        // Menembak API OSRM Publik untuk mendapatkan Rute Jalan Raya Nyata
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${lng},${lat};${SCHOOL_LNG},${SCHOOL_LAT}?overview=full&geometries=geojson`;
        const response = await fetch(osrmUrl);
        const data = await response.json();

        if (data && data.routes && data.routes.length > 0) {
            const route = data.routes[0];
            
            // Update Jarak (OSRM mengembalikan meter)
            distanceKm.value = route.distance / 1000;
            
            // Gambar Garis Peta Real Road
            const coordinates = route.geometry.coordinates.map(c => [c[1], c[0]]); // GeoJSON [lng, lat] ke Leaflet [lat, lng]
            routeLine = L.polyline(coordinates, {
                color: "blue",
                weight: 5,
                opacity: 0.8,
            }).addTo(map);

        } else {
            throw new Error("No route found");
        }
    } catch (error) {
        console.warn("OSRM Failed, falling back to Haversine straight line", error);
        
        // Fallback: Garis Lurus (Burung) + Perhitungan Rumus Matematika Klasik
        distanceKm.value = calculateDistance(lat, lng, SCHOOL_LAT, SCHOOL_LNG);
        routeLine = L.polyline(
            [
                [lat, lng],
                [SCHOOL_LAT, SCHOOL_LNG],
            ],
            {
                color: "gray",
                dashArray: "5, 10",
                weight: 3,
            },
        ).addTo(map);
    }

    // Update Harga secara Dinamis berdasarkan Rumus Linier (Reverse Engineering Tabel)
    // Harga Paket Utama = 200.000 + (Jarak KM * 50.000)
    let price = BASE_PRICE + (distanceKm.value * PRICE_PER_KM);
    
    // Harga Pulang/Pergi Saja = 52% dari Harga Paket
    let oneWayPrice = price * 0.52;

    // Bulatkan ke ribuan terdekat agar angkanya cantik/rapi seperti di tabel
    estimatedPrice.value = Math.round(price / 1000) * 1000;
    estimatedPriceOneWay.value = Math.round(oneWayPrice / 1000) * 1000;
};

// Fungsi Pencarian Alamat ke Nominatim OSM (GRATIS)
const searchAddress = async () => {
    if (!searchQuery.value) return;

    isSearching.value = true;
    try {
        // Tembak API Nominatim (tambah Lembang biar pencarian fokus di area target)
        const response = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&q=${searchQuery.value}, Lembang`,
        );
        const data = await response.json();

        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);

            // Update lokasi & suruh peta terbang ke sana
            updateLocation(lat, lng);
            map.flyTo([lat, lng], 16);
        } else {
            alert(
                "Alamat tidak ditemukan. Coba ketik nama jalan raya yang lebih umum, lalu geser pin-nya manual ke dalam gang.",
            );
        }
    } catch (error) {
        console.error("Error fetching address:", error);
    } finally {
        isSearching.value = false;
    }
};

onMounted(() => {
    map = L.map("landing-map").setView([SCHOOL_LAT, SCHOOL_LNG], 13);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors",
    }).addTo(map);

    L.circleMarker([SCHOOL_LAT, SCHOOL_LNG], {
        color: "red",
        fillColor: "red",
        fillOpacity: 1,
        radius: 8,
    })
        .addTo(map)
        .bindPopup("<b>Sekolah (Tujuan)</b>")
        .openPopup();

    map.on("click", (e) => updateLocation(e.latlng.lat, e.latlng.lng));
});

const formatRupiah = (angka) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(angka);
};
</script>

<template>
    <Head title="Cek Harga Antar-Jemput" />

    <div
        class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4"
    >
        <div
            class="max-w-5xl w-full bg-white rounded-xl shadow-lg overflow-hidden"
        >
            <div class="bg-blue-600 p-6 text-white text-center">
                <h1 class="text-3xl font-bold">
                    Koperasi Bisa Berdikari Usaha
                </h1>
                <p class="mt-2 opacity-90">
                    Layanan Antar-Jemput Siswa Terintegrasi area Lembang
                </p>
            </div>

            <div class="p-6 md:flex gap-6">
                <div class="md:w-2/3 flex flex-col">
                    <h2 class="text-lg font-bold text-gray-800 mb-2">
                        1. Tentukan Titik Jemput
                    </h2>

                    <div class="flex gap-2 mb-4">
                        <input
                            v-model="searchQuery"
                            @keyup.enter="searchAddress"
                            type="text"
                            placeholder="Contoh: Jl. Maribaya No 10..."
                            class="w-full border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500 shadow-sm"
                        />
                        <button
                            @click="searchAddress"
                            :disabled="isSearching"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition-colors disabled:opacity-50"
                        >
                            {{ isSearching ? "Mencari..." : "Cari" }}
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 mb-2">
                        Atau klik langsung pada peta.
                        <b>Anda bisa menggeser (drag) pin biru</b> ke dalam gang
                        untuk lokasi yang presisi.
                    </p>

                    <div
                        id="landing-map"
                        class="h-[400px] w-full rounded border-2 border-gray-300 z-0 flex-grow"
                    ></div>
                </div>

                <div class="md:w-1/3 mt-6 md:mt-0 flex flex-col justify-center">
                    <div
                        class="bg-gray-100 p-6 rounded-lg border border-gray-200 text-center"
                    >
                        <h3 class="text-gray-500 font-semibold mb-2">
                            Estimasi Tarif per Bulan
                        </h3>

                        <div v-if="distanceKm > 0">
                            <p
                                class="text-3xl font-extrabold text-blue-600 my-4"
                            >
                                <span class="text-sm text-gray-500 font-normal block mb-1">Paket Antar Jemput (PP):</span>
                                {{ formatRupiah(estimatedPrice) }}
                            </p>
                            
                            <p
                                class="text-xl font-bold text-orange-500 mb-6 pb-4 border-b border-gray-200"
                            >
                                <span class="text-sm text-gray-500 font-normal block mb-1">Paket 1 Arah (Pergi/Pulang Saja):</span>
                                {{ formatRupiah(estimatedPriceOneWay) }}
                            </p>

                            <div class="text-sm text-gray-600 space-y-1 mb-4">
                                <p>
                                    Jarak Rute Aspal:
                                    <b>{{ distanceKm.toFixed(2) }} KM</b>
                                </p>
                                <p>
                                    Tarif Dasar: {{ formatRupiah(BASE_PRICE) }}
                                </p>
                                <p>
                                    Tarif Jarak:
                                    {{ formatRupiah(PRICE_PER_KM) }} / KM
                                </p>
                            </div>

                            <hr class="my-4 border-gray-300" />

                            <Link
                                v-if="canRegister"
                                :href="
                                    route('register', {
                                        lat: userLat,
                                        lng: userLng,
                                        distance: distanceKm,
                                        price: estimatedPrice,
                                    })
                                "
                                class="block w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded transition duration-200"
                            >
                                Mulai Berlangganan
                            </Link>
                        </div>

                        <div v-else class="py-8 text-gray-400">
                            <svg
                                class="w-12 h-12 mx-auto mb-3"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"
                                ></path>
                            </svg>
                            <p>
                                Cari alamat atau klik lokasi rumah Anda di peta.
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <p class="text-sm text-gray-500">Sudah punya akun?</p>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="text-blue-600 font-semibold hover:underline"
                        >
                            Login di sini
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
