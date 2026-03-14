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

// =========================
// STATE
// =========================
const userLat = ref(null);
const userLng = ref(null);

const distanceMeters = ref(0);   // dipakai untuk hitung harga
const distanceKm = ref(0);       // dipakai untuk tampilan
const durationMin = ref(0);

const estimatedTripFare = ref(0);      // 1x perjalanan
const estimatedPrice = ref(0);         // bulanan PP
const estimatedPriceOneWay = ref(0);   // bulanan 1 arah

const accessSurcharge = ref(0);

const monthlyDistanceCharge = ref(0);
const monthlyDurationCharge = ref(0);

// =========================
// KONFIGURASI PRICING
// =========================
const BASE_MONTHLY_PP = 250000;
const RATE_MONTHLY_PER_MINUTE_PP = 1000;
const ONE_WAY_RATIO = 0.52;

const DISTANCE_BANDS = [
    { upto: 1000, rate: 15 },      // 0 - 1 km
    { upto: 2000, rate: 50 },      // 1 - 2 km
    { upto: 4000, rate: 55 },      // 2 - 4 km
    { upto: 10000, rate: 13 },     // 4 - 10 km
    { upto: Infinity, rate: 8 },   // > 10 km
];

const FALLBACK_SPEED_KMH = 18;

// =========================
// STATE PENCARIAN
// =========================
const searchQuery = ref("");
const isSearching = ref(false);

let map = null;
let userMarker = null;
let routeLine = null;

// =========================
// HELPERS
// =========================

const calculateDistanceCharge = (meters) => {
    let total = 0;
    let previousLimit = 0;

    for (const band of DISTANCE_BANDS) {
        const upperLimit = band.upto;

        const bandMeters =
            upperLimit === Infinity
                ? Math.max(0, meters - previousLimit)
                : Math.max(0, Math.min(meters, upperLimit) - previousLimit);

        total += bandMeters * band.rate;
        previousLimit = upperLimit;

        if (meters <= upperLimit) break;
    }

    return total;
};

const ceilToStep = (value, step = 1000) => Math.ceil(value / step) * step;

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

const calculatePricing = ({
    distanceMeters,
    durationMin,
    accessSurcharge = 0,
}) => {
    const distanceCharge = calculateDistanceCharge(distanceMeters);
    const durationCharge = durationMin * RATE_MONTHLY_PER_MINUTE_PP;

    const monthlyPPRaw =
        BASE_MONTHLY_PP +
        distanceCharge +
        durationCharge +
        accessSurcharge;

    const monthlyPP = Math.ceil(monthlyPPRaw / 1000) * 1000;
    const monthlyOneWay = Math.ceil((monthlyPP * ONE_WAY_RATIO) / 1000) * 1000;

    const estimatedTripFare = Math.ceil((monthlyOneWay / 22) / 500) * 500;

    return {
        monthlyPP,
        monthlyOneWay,
        estimatedTripFare,
        distanceCharge,
        durationCharge,
    };
};

const applyPricing = () => {
    const pricing = calculatePricing({
        distanceMeters: distanceMeters.value,
        durationMin: durationMin.value,
        accessSurcharge: accessSurcharge.value,
    });

    estimatedTripFare.value = pricing.estimatedTripFare;
    estimatedPriceOneWay.value = pricing.monthlyOneWay;
    estimatedPrice.value = pricing.monthlyPP;
    monthlyDistanceCharge.value = pricing.distanceCharge;
    monthlyDurationCharge.value = pricing.durationCharge;
};

// =========================
// UPDATE LOKASI + ROUTING + PRICING
// =========================
const updateLocation = async (lat, lng) => {
    userLat.value = lat;
    userLng.value = lng;

    if (!userMarker) {
        userMarker = L.marker([lat, lng], { draggable: true })
            .addTo(map)
            .bindPopup("<b>Geser pin ini ke depan rumah Anda</b>")
            .openPopup();

        userMarker.on("dragend", function () {
            const position = userMarker.getLatLng();
            updateLocation(position.lat, position.lng);
            map.panTo(position);
        });
    } else {
        userMarker.setLatLng([lat, lng]);
    }

    if (routeLine) {
        map.removeLayer(routeLine);
    }

    try {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${lng},${lat};${SCHOOL_LNG},${SCHOOL_LAT}?overview=full&geometries=geojson`;
        const response = await fetch(osrmUrl);
        const data = await response.json();

        if (data?.routes?.length > 0) {
            const route = data.routes[0];

            distanceMeters.value = route.distance;
            distanceKm.value = route.distance / 1000;
            durationMin.value = route.duration / 60;

            const coordinates = route.geometry.coordinates.map((c) => [c[1], c[0]]);
            routeLine = L.polyline(coordinates, {
                color: "blue",
                weight: 5,
                opacity: 0.8,
            }).addTo(map);
        } else {
            throw new Error("No route found");
        }
    } catch (error) {
        console.warn("OSRM failed, fallback to straight line", error);

        distanceKm.value = calculateDistance(lat, lng, SCHOOL_LAT, SCHOOL_LNG);
        distanceMeters.value = distanceKm.value * 1000;
        durationMin.value = (distanceKm.value / FALLBACK_SPEED_KMH) * 60;

        routeLine = L.polyline(
            [
                [lat, lng],
                [SCHOOL_LAT, SCHOOL_LNG],
            ],
            {
                color: "gray",
                dashArray: "5, 10",
                weight: 3,
            }
        ).addTo(map);
    }

    applyPricing();
};

// =========================
// SEARCH ADDRESS
// =========================
const searchAddress = async () => {
    if (!searchQuery.value) return;

    isSearching.value = true;
    try {
        const response = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(
                `${searchQuery.value}, Lembang, Bandung Barat`
            )}`
        );

        const data = await response.json();

        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);

            updateLocation(lat, lng);
            map.flyTo([lat, lng], 16);
        } else {
            alert(
                "Alamat tidak ditemukan. Coba ketik nama jalan yang lebih umum, lalu geser pin manual ke lokasi rumah."
            );
        }
    } catch (error) {
        console.error("Error fetching address:", error);
        alert("Gagal mencari alamat. Silakan coba lagi.");
    } finally {
        isSearching.value = false;
    }
};

// =========================
// INIT MAP
// =========================
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
                            <p class="text-3xl font-extrabold text-blue-600 my-4">
                                <span class="text-sm text-gray-500 font-normal block mb-1">
                                    Paket Antar Jemput (PP):
                                </span>
                                {{ formatRupiah(estimatedPrice) }}
                            </p>

                            <p class="text-xl font-bold text-orange-500 mb-4 pb-4 border-b border-gray-200">
                                <span class="text-sm text-gray-500 font-normal block mb-1">
                                    Paket 1 Arah (Pergi / Pulang Saja):
                                </span>
                                {{ formatRupiah(estimatedPriceOneWay) }}
                            </p>

                            <div class="bg-white border rounded-lg p-4 text-left mb-4">
                                <h4 class="font-semibold text-gray-700 mb-2">Ringkasan Estimasi</h4>

                                <div class="text-sm text-gray-600 space-y-1">
                                    <p>Jarak rute jalan: <b>{{ distanceKm.toFixed(2) }} KM</b></p>
                                    <p>Durasi rute: <b>{{ Math.ceil(durationMin) }} menit</b></p>
                                    <p>Estimasi 1x perjalanan: <b>{{ formatRupiah(estimatedTripFare) }}</b></p>
                                </div>
                            </div>

                            <div class="text-sm text-gray-600 space-y-1 mb-4">
                                <p>Tarif dasar bulanan PP: {{ formatRupiah(BASE_MONTHLY_PP) }}</p>
                                <p>Model tarif jarak: bertingkat berdasarkan jarak rute</p>
                                <p>Tarif waktu bulanan: {{ formatRupiah(RATE_MONTHLY_PER_MINUTE_PP) }} / menit</p>
                                <p>Rasio paket 1 arah: {{ Math.round(ONE_WAY_RATIO * 100) }}%</p>
                            </div>

                            <div class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded p-3 mb-4">
                                Estimasi tarif dihitung otomatis berdasarkan titik jemput, jarak rute, dan durasi.
                                Untuk area akses khusus, harga final dapat diverifikasi admin.
                            </div>

                            <hr class="my-4 border-gray-300" />

                            <Link
                                v-if="canRegister"
                                :href="
                                    route('register', {
                                        lat: userLat,
                                        lng: userLng,
                                        distance_km: distanceKm,
                                        distance_meters: distanceMeters,
                                        duration: durationMin,
                                        price: estimatedPrice,
                                        price_one_way: estimatedPriceOneWay,
                                        trip_fare: estimatedTripFare,
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
