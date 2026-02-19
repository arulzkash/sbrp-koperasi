<script setup>
import { ref, onMounted } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});

const SCHOOL_LAT = -6.815348;
const SCHOOL_LNG = 107.616659;

const userLat = ref(null);
const userLng = ref(null);
const distanceKm = ref(0);
const estimatedPrice = ref(0);

const BASE_PRICE = 100000;
const PRICE_PER_KM = 2500;

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
const updateLocation = (lat, lng) => {
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

    distanceKm.value = calculateDistance(lat, lng, SCHOOL_LAT, SCHOOL_LNG);
    estimatedPrice.value = Math.round(BASE_PRICE + distanceKm.value * PRICE_PER_KM * 30);

    if (routeLine) map.removeLayer(routeLine);
    routeLine = L.polyline([[lat, lng], [SCHOOL_LAT, SCHOOL_LNG]], {
        color: "blue",
        dashArray: "5, 10",
        weight: 2,
    }).addTo(map);
};

// Fungsi Pencarian Alamat ke Nominatim OSM (GRATIS)
const searchAddress = async () => {
    if (!searchQuery.value) return;
    
    isSearching.value = true;
    try {
        // Tembak API Nominatim (tambah Lembang biar pencarian fokus di area target)
        const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${searchQuery.value}, Lembang`);
        const data = await response.json();

        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);

            // Update lokasi & suruh peta terbang ke sana
            updateLocation(lat, lng);
            map.flyTo([lat, lng], 16); 
        } else {
            alert("Alamat tidak ditemukan. Coba ketik nama jalan raya yang lebih umum, lalu geser pin-nya manual ke dalam gang.");
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
    }).addTo(map).bindPopup("<b>Sekolah (Tujuan)</b>").openPopup();

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

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4">
        <div class="max-w-5xl w-full bg-white rounded-xl shadow-lg overflow-hidden">
            
            <div class="bg-blue-600 p-6 text-white text-center">
                <h1 class="text-3xl font-bold">Koperasi Bisa Berdikari Usaha</h1>
                <p class="mt-2 opacity-90">Layanan Antar-Jemput Siswa Terintegrasi area Lembang</p>
            </div>

            <div class="p-6 md:flex gap-6">
                <div class="md:w-2/3 flex flex-col">
                    <h2 class="text-lg font-bold text-gray-800 mb-2">1. Tentukan Titik Jemput</h2>
                    
                    <div class="flex gap-2 mb-4">
                        <input 
                            v-model="searchQuery"
                            @keyup.enter="searchAddress"
                            type="text" 
                            placeholder="Contoh: Jl. Maribaya No 10..." 
                            class="w-full border-gray-300 rounded focus:border-blue-500 focus:ring-blue-500 shadow-sm"
                        >
                        <button 
                            @click="searchAddress"
                            :disabled="isSearching"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition-colors disabled:opacity-50"
                        >
                            {{ isSearching ? 'Mencari...' : 'Cari' }}
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 mb-2">
                        Atau klik langsung pada peta. <b>Anda bisa menggeser (drag) pin biru</b> ke dalam gang untuk lokasi yang presisi.
                    </p>
                    
                    <div id="landing-map" class="h-[400px] w-full rounded border-2 border-gray-300 z-0 flex-grow"></div>
                </div>

                <div class="md:w-1/3 mt-6 md:mt-0 flex flex-col justify-center">
                    <div class="bg-gray-100 p-6 rounded-lg border border-gray-200 text-center">
                        <h3 class="text-gray-500 font-semibold mb-2">Estimasi Tarif per Bulan</h3>
                        
                        <div v-if="distanceKm > 0">
                            <p class="text-4xl font-extrabold text-blue-600 my-4">
                                {{ formatRupiah(estimatedPrice) }}
                            </p>
                            <div class="text-sm text-gray-600 space-y-1">
                                <p>Jarak ke sekolah: <b>{{ distanceKm.toFixed(2) }} KM</b></p>
                                <p>Tarif Dasar: {{ formatRupiah(BASE_PRICE) }}</p>
                                <p>Tarif Jarak: {{ formatRupiah(PRICE_PER_KM) }} / KM / Hari</p>
                            </div>
                            
                            <hr class="my-4 border-gray-300">
                            
                            <Link 
                                v-if="canRegister"
                                :href="route('register')" 
                                class="block w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded transition duration-200"
                            >
                                Mulai Berlangganan
                            </Link>
                        </div>

                        <div v-else class="py-8 text-gray-400">
                            <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
                            <p>Cari alamat atau klik lokasi rumah Anda di peta.</p>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <p class="text-sm text-gray-500">Sudah punya akun?</p>
                        <Link v-if="canLogin" :href="route('login')" class="text-blue-600 font-semibold hover:underline">
                            Login di sini
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>