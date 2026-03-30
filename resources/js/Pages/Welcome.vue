<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
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

const distanceMeters = ref(0);
const distanceKm = ref(0);
const durationMin = ref(0);

const estimatedTripFare = ref(0);
const estimatedPrice = ref(0);
const estimatedPriceOneWay = ref(0);

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
    { upto: 1000, rate: 15 },
    { upto: 2000, rate: 50 },
    { upto: 4000, rate: 55 },
    { upto: 10000, rate: 13 },
    { upto: Infinity, rate: 8 },
];

const FALLBACK_SPEED_KMH = 18;

// =========================
// STATE PENCARIAN
// =========================
const searchQuery = ref("");
const isSearching = ref(false);
const isRouting = ref(false);
const isRoutePending = ref(false);

let map = null;
let userMarker = null;
let routeLine = null;
let routeDebounceTimer = null;
let routeRequestSequence = 0;
let latestRouteRequestId = 0;
let activeRouteController = null;

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
        BASE_MONTHLY_PP + distanceCharge + durationCharge + accessSurcharge;

    const monthlyPP = Math.ceil(monthlyPPRaw / 1000) * 1000;
    const monthlyOneWay = Math.ceil((monthlyPP * ONE_WAY_RATIO) / 1000) * 1000;

    const estimatedTripFare = Math.ceil(monthlyOneWay / 22 / 500) * 500;

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

const renderFallbackRoute = (lat, lng) => {
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
        },
    ).addTo(map);
};

const runRouteCalculation = async (lat, lng) => {
    const requestId = ++routeRequestSequence;
    latestRouteRequestId = requestId;

    isRoutePending.value = false;
    isRouting.value = true;

    if (activeRouteController) {
        activeRouteController.abort();
    }

    activeRouteController = new AbortController();

    if (routeLine) {
        map.removeLayer(routeLine);
        routeLine = null;
    }

    try {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${lng},${lat};${SCHOOL_LNG},${SCHOOL_LAT}?overview=full&geometries=geojson`;
        const response = await fetch(osrmUrl, {
            signal: activeRouteController.signal,
        });
        const data = await response.json();

        if (requestId !== latestRouteRequestId) {
            return;
        }

        if (data?.routes?.length > 0) {
            const route = data.routes[0];

            distanceMeters.value = route.distance;
            distanceKm.value = route.distance / 1000;
            durationMin.value = route.duration / 60;

            const coordinates = route.geometry.coordinates.map((c) => [
                c[1],
                c[0],
            ]);
            routeLine = L.polyline(coordinates, {
                color: "#2563eb",
                weight: 5,
                opacity: 0.8,
            }).addTo(map);
        } else {
            throw new Error("No route found");
        }
    } catch (error) {
        if (error.name === "AbortError") {
            return;
        }

        if (requestId !== latestRouteRequestId) {
            return;
        }

        console.warn("OSRM failed, fallback to straight line", error);
        renderFallbackRoute(lat, lng);
    } finally {
        if (requestId === latestRouteRequestId) {
            applyPricing();
            isRouting.value = false;
            activeRouteController = null;
        }
    }
};

// =========================
// UPDATE LOKASI + ROUTING + PRICING
// =========================
const updateLocation = (lat, lng) => {
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

    if (routeDebounceTimer) {
        clearTimeout(routeDebounceTimer);
    }

    isRoutePending.value = true;

    routeDebounceTimer = setTimeout(() => {
        runRouteCalculation(lat, lng);
    }, 1000);
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
                `${searchQuery.value}, Lembang, Bandung Barat`,
            )}`,
        );

        const data = await response.json();

        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);

            updateLocation(lat, lng);
            map.flyTo([lat, lng], 16);
        } else {
            alert(
                "Alamat tidak ditemukan. Coba ketik nama jalan yang lebih umum, lalu geser pin manual ke lokasi rumah.",
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

onBeforeUnmount(() => {
    if (routeDebounceTimer) {
        clearTimeout(routeDebounceTimer);
    }

    if (activeRouteController) {
        activeRouteController.abort();
    }
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

    <div class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6 lg:px-8 lg:py-6">
            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-4 text-white sm:px-8 sm:py-6"
                >
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="text-[10px] font-semibold uppercase tracking-[0.2em] text-blue-100"
                        >
                            Antar Jemput Siswa
                        </p>
                        <h1 class="mt-1 text-xl font-bold sm:text-2xl">
                            Koperasi Bisa Berdikari Usaha
                        </h1>
                        <p class="mt-1 text-xs text-blue-100 sm:text-sm">
                            Cek estimasi tarif area Lembang berdasarkan titik
                            jemput dan rute jalan.
                        </p>

                        <div
                            class="mt-4 grid grid-cols-1 gap-2 text-left sm:grid-cols-3"
                        >
                            <div
                                class="rounded-lg border border-white/20 bg-white/10 px-3 py-2"
                            >
                                <p
                                    class="text-[10px] font-bold uppercase tracking-wide text-blue-100"
                                >
                                    Langkah 1
                                </p>
                                <p class="mt-1 text-xs text-white">
                                    Cari alamat atau klik peta untuk menentukan
                                    titik jemput.
                                </p>
                            </div>

                            <div
                                class="rounded-lg border border-white/20 bg-white/10 px-3 py-2"
                            >
                                <p
                                    class="text-[10px] font-bold uppercase tracking-wide text-blue-100"
                                >
                                    Langkah 2
                                </p>
                                <p class="mt-1 text-xs text-white">
                                    Lihat estimasi tarif berdasarkan jarak dan
                                    durasi rute.
                                </p>
                            </div>

                            <div
                                class="rounded-lg border border-white/20 bg-white/10 px-3 py-2"
                            >
                                <p
                                    class="text-[10px] font-bold uppercase tracking-wide text-blue-100"
                                >
                                    Langkah 3
                                </p>
                                <p class="mt-1 text-xs text-white">
                                    Lanjut daftar untuk proses verifikasi
                                    pembayaran dan penentuan rute.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-4 sm:p-5 lg:p-6 lg:border-b-0">
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        <!-- MAP SECTION -->
                        <div class="lg:col-span-8 space-y-4">
                            <div
                                class="flex flex-col sm:flex-row gap-3 items-center justify-between"
                            >
                                <div class="flex-shrink-0">
                                    <h2
                                        class="text-base font-semibold text-slate-900"
                                    >
                                        1. Tentukan titik jemput
                                    </h2>
                                </div>
                                <div class="flex flex-1 w-full gap-2">
                                    <input
                                        v-model="searchQuery"
                                        @keyup.enter="searchAddress"
                                        type="text"
                                        placeholder="Cari jalan/alamat..."
                                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                    <button
                                        @click="searchAddress"
                                        :disabled="isSearching"
                                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ isSearching ? "..." : "Cari" }}
                                    </button>
                                </div>
                            </div>

                            <div
                                class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-inner"
                            >
                                <div
                                    id="landing-map"
                                    class="h-[300px] w-full sm:h-[350px] lg:h-[400px]"
                                ></div>
                            </div>
                            <p
                                class="text-[11px] leading-5 text-slate-500 italic"
                            >
                                * Klik peta atau
                                <span class="font-semibold">drag pin biru</span>
                                untuk lokasi presisi (depan rumah).
                            </p>
                            <p
                                v-if="isRoutePending || isRouting"
                                class="text-[11px] leading-5 text-blue-600"
                            >
                                {{
                                    isRoutePending
                                        ? "Menunggu 1 detik sebelum menghitung rute terbaru..."
                                        : "Sedang menghitung visual rute terbaru..."
                                }}
                            </p>
                        </div>

                        <!-- PRICING SECTION -->
                        <div class="lg:col-span-4 space-y-3">
                            <div
                                class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                            >
                                <div v-if="distanceKm > 0" class="space-y-3">
                                    <div
                                        class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2"
                                    >
                                        <p
                                            class="text-[10px] font-bold uppercase tracking-wide text-blue-700"
                                        >
                                            Estimasi Awal
                                        </p>
                                        <p
                                            class="mt-1 text-xs leading-4 text-blue-800"
                                        >
                                            Tarif dihitung dari jarak rute jalan
                                            dan durasi tempuh menuju sekolah.
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-lg bg-blue-600 p-4 text-white shadow-sm"
                                    >
                                        <p
                                            class="text-[10px] font-medium uppercase tracking-wide text-blue-100"
                                        >
                                            Estimasi Biaya Bulanan
                                        </p>
                                        <p class="mt-1 text-2xl font-extrabold">
                                            {{ formatRupiah(estimatedPrice) }}
                                            <span
                                                class="text-xs font-normal text-blue-100"
                                                >/bulan</span
                                            >
                                        </p>
                                    </div>

                                    <div
                                        class="rounded-lg border border-orange-100 bg-white p-3 ring-1 ring-orange-50"
                                    >
                                        <p
                                            class="text-[10px] font-medium uppercase tracking-wide text-orange-600"
                                        >
                                            Paket 1 Arah
                                        </p>
                                        <p
                                            class="mt-0.5 text-lg font-bold text-orange-600"
                                        >
                                            {{
                                                formatRupiah(
                                                    estimatedPriceOneWay,
                                                )
                                            }}
                                            <span
                                                class="text-[10px] font-normal text-orange-400 text-right"
                                                >/bulan</span
                                            >
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <div
                                            class="rounded-lg border border-slate-100 bg-white p-2"
                                        >
                                            <p
                                                class="text-[10px] text-slate-500"
                                            >
                                                Jarak rute
                                            </p>
                                            <p
                                                class="text-xs font-semibold text-slate-900"
                                            >
                                                {{ distanceKm.toFixed(2) }} KM
                                            </p>
                                        </div>
                                        <div
                                            class="rounded-lg border border-slate-100 bg-white p-2"
                                        >
                                            <p
                                                class="text-[10px] text-slate-500"
                                            >
                                                Durasi
                                            </p>
                                            <p
                                                class="text-xs font-semibold text-slate-900"
                                            >
                                                {{ Math.ceil(durationMin) }} mnt
                                            </p>
                                        </div>
                                    </div>

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
                                                price_one_way:
                                                    estimatedPriceOneWay,
                                                trip_fare: estimatedTripFare,
                                            })
                                        "
                                        class="inline-flex w-full items-center justify-center rounded-xl bg-green-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-green-700"
                                    >
                                        Lanjut Daftar dengan Titik Ini
                                    </Link>

                                    <div
                                        class="rounded-lg border border-amber-100 bg-amber-50 p-2 text-[10px] leading-4 text-amber-700"
                                    >
                                        * Estimasi ini menjadi dasar pendaftaran. Verifikasi akhir dilakukan admin.
                                    </div>
                                </div>

                                <div
                                    v-else
                                    class="rounded-xl border border-dashed border-slate-300 bg-white py-10 text-center text-slate-400"
                                >
                                    <svg
                                        class="mx-auto mb-3 h-8 w-8"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"
                                        />
                                    </svg>

                                    <p
                                        class="text-sm font-semibold text-slate-700"
                                    >
                                        Pilih titik jemput untuk melihat
                                        estimasi
                                    </p>

                                    <p
                                        class="mx-auto mt-2 max-w-xs text-xs leading-5 text-slate-500"
                                    >
                                        Cari alamat, klik peta, atau geser pin
                                        agar sistem menghitung jarak rute dan
                                        estimasi biaya bulanan.
                                    </p>
                                </div>
                            </div>

                            <div v-if="canLogin" class="text-center pb-2">
                                <p class="text-xs text-slate-500">
                                    Sudah punya akun?
                                    <Link
                                        :href="route('login')"
                                        class="font-semibold text-blue-600 hover:text-blue-700"
                                        >Login</Link
                                    >
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
