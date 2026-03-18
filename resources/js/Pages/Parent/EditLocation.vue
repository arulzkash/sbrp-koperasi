<script setup>
import { ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    student: Object,
});

// =========================
// KOORDINAT SEKOLAH
// =========================
const SCHOOL_LAT = -6.826864390637824;
const SCHOOL_LNG = 107.63886429303408;

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
// FORM DATA
// =========================
const form = useForm({
    address_text:
        props.student.address_text === 'Alamat dari Pin Map'
            ? ''
            : props.student.address_text,

    latitude: props.student.latitude,
    longitude: props.student.longitude,

    distance: props.student.distance_to_school_meters / 1000,

    price: props.student.price_per_month,
    one_way_price: Math.round((props.student.price_per_month * 0.52) / 1000) * 1000,
});

let map = null;
let userMarker = null;
let routeLine = null;

// =========================
// HITUNG JARAK HAVERSINE
// =========================
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

// =========================
// PIECEWISE DISTANCE PRICING
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

// =========================
// PRICING ENGINE
// =========================
const calculatePricing = (distanceMeters, durationMin) => {
    const distanceCharge = calculateDistanceCharge(distanceMeters);
    const durationCharge = durationMin * RATE_MONTHLY_PER_MINUTE_PP;

    const monthlyPPRaw =
        BASE_MONTHLY_PP +
        distanceCharge +
        durationCharge;

    const monthlyPP = Math.ceil(monthlyPPRaw / 1000) * 1000;

    const monthlyOneWay =
        Math.ceil((monthlyPP * ONE_WAY_RATIO) / 1000) * 1000;

    return {
        monthlyPP,
        monthlyOneWay,
    };
};

// =========================
// UPDATE MAP + PRICING
// =========================
const updateMapAndData = async (lat, lng) => {
    form.latitude = lat;
    form.longitude = lng;

    if (userMarker) {
        userMarker.setLatLng([lat, lng]);
    }

    if (routeLine) map.removeLayer(routeLine);

    try {
        const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${lng},${lat};${SCHOOL_LNG},${SCHOOL_LAT}?overview=full&geometries=geojson`;

        const response = await fetch(osrmUrl);
        const data = await response.json();

        if (data?.routes?.length > 0) {
            const route = data.routes[0];

            const distanceMeters = route.distance;
            const durationMin = route.duration / 60;

            form.distance = distanceMeters / 1000;

            const pricing = calculatePricing(distanceMeters, durationMin);

            form.price = pricing.monthlyPP;
            form.one_way_price = pricing.monthlyOneWay;

            const coordinates = route.geometry.coordinates.map((c) => [c[1], c[0]]);

            routeLine = L.polyline(coordinates, {
                color: "#2563eb",
                weight: 5,
                opacity: 0.8,
            }).addTo(map);

        } else {
            throw new Error("Rute OSRM tidak ditemukan");
        }

    } catch (error) {

        console.warn("OSRM gagal, menggunakan fallback Haversine", error);

        const distanceKm = calculateDistance(lat, lng, SCHOOL_LAT, SCHOOL_LNG);

        const distanceMeters = distanceKm * 1000;

        const durationMin = (distanceKm / FALLBACK_SPEED_KMH) * 60;

        form.distance = distanceKm;

        const pricing = calculatePricing(distanceMeters, durationMin);

        form.price = pricing.monthlyPP;
        form.one_way_price = pricing.monthlyOneWay;

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
};

// =========================
// INIT MAP
// =========================
onMounted(() => {

    map = L.map("edit-map").setView(
        [form.latitude, form.longitude],
        15
    );

    L.tileLayer(
        "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            attribution: "© OpenStreetMap contributors",
        }
    ).addTo(map);

    L.circleMarker([SCHOOL_LAT, SCHOOL_LNG], {
        color: "red",
        fillColor: "red",
        fillOpacity: 1,
        radius: 8,
    })
        .addTo(map)
        .bindPopup("<b>Sekolah</b>");

    userMarker = L.marker(
        [form.latitude, form.longitude],
        { draggable: true }
    )
        .addTo(map)
        .bindPopup("<b>Geser pin ini ke lokasi persis rumah</b>")
        .openPopup();

    userMarker.on("dragend", function () {

        const position = userMarker.getLatLng();

        updateMapAndData(position.lat, position.lng);

        map.panTo(position);
    });

    updateMapAndData(form.latitude, form.longitude);
});

// =========================
// SUBMIT
// =========================
const submit = () => {
    form.put(route("location.update", props.student.id));
};

// =========================
// FORMAT RUPIAH
// =========================
const formatRupiah = (angka) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(angka);
};
</script>

<template>
    <Head title="Update Titik Jemput" />

    <AuthenticatedLayout>

        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Update Lokasi Jemputan: {{ student.name }}
            </h2>
        </template>

        <div class="py-6 sm:py-8 lg:py-10">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <form
                    @submit.prevent="submit"
                    class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col md:flex-row gap-6"
                >
                    <div class="md:w-1/3 flex flex-col gap-4">
                        <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100">
                            <h3 class="font-bold text-blue-800 text-sm mb-1">Instruksi:</h3>
                            <ul class="text-xs text-blue-700 list-disc pl-4 space-y-0.5">
                                <li>Geser pin ke posisi rumah yang paling presisi.</li>
                                <li>Tambahkan patokan rumah pada alamat.</li>
                            </ul>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 text-xs mb-1">
                                Detail Alamat Lengkap
                            </label>
                            <textarea
                                v-model="form.address_text"
                                rows="3"
                                placeholder="Gg. Kancil No. 5 (Pagar Hitam)"
                                class="w-full border-slate-200 rounded-xl text-sm focus:ring-blue-500 focus:border-blue-500"
                                required
                            ></textarea>
                        </div>

                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 mt-auto space-y-3">
                            <div class="flex justify-between items-center text-xs text-slate-500 mb-1">
                                <span>Jarak Rute Aspal:</span>
                                <b class="text-slate-900">{{ form.distance.toFixed(2) }} KM</b>
                            </div>

                            <div class="grid grid-cols-1 gap-2">
                                <div class="bg-white p-2.5 rounded-lg border border-slate-100">
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-0.5">
                                        Tarif PP
                                    </p>
                                    <b class="text-blue-600 text-base">
                                        {{ formatRupiah(form.price) }}
                                    </b>
                                </div>

                                <div class="bg-white p-2.5 rounded-lg border border-slate-100">
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold mb-0.5">
                                        Paket 1 Arah
                                    </p>
                                    <b class="text-orange-500 text-sm">
                                        {{ formatRupiah(form.one_way_price) }}
                                    </b>
                                </div>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition disabled:opacity-50"
                            >
                                {{ form.processing ? "..." : "Simpan Perubahan" }}
                            </button>
                        </div>
                    </div>

                    <div class="md:w-2/3">
                        <div
                            id="edit-map"
                            class="h-[350px] sm:h-[400px] lg:h-[450px] w-full rounded-2xl border border-slate-200 shadow-inner overflow-hidden"
                        ></div>
                        <p class="text-[10px] text-slate-400 mt-2 text-center italic">
                            * Marker ditarik ke depan gerbang rumah untuk akurasi penjemputan.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>