<script setup>
import { ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    student: Object,
});

// Koordinat Sekolah
const SCHOOL_LAT = -6.815348;
const SCHOOL_LNG = 107.616659;
const BASE_PRICE = 100000;
const PRICE_PER_KM = 2500;

// Setup Form Inertia bawaan dengan data dari database
const form = useForm({
    address_text: props.student.address_text === 'Alamat dari Pin Map' ? '' : props.student.address_text,
    latitude: props.student.latitude,
    longitude: props.student.longitude,
    distance: props.student.distance_to_school_meters / 1000,
    price: props.student.price_per_month,
});

let map = null;
let userMarker = null;
let routeLine = null;

const calculateDistance = (lat1, lon1, lat2, lon2) => {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
};

const updateMapAndData = (lat, lng) => {
    form.latitude = lat;
    form.longitude = lng;

    if (userMarker) {
        userMarker.setLatLng([lat, lng]);
    }

    form.distance = calculateDistance(lat, lng, SCHOOL_LAT, SCHOOL_LNG);
    form.price = Math.round(BASE_PRICE + form.distance * PRICE_PER_KM * 30);

    if (routeLine) map.removeLayer(routeLine);
    routeLine = L.polyline([[lat, lng], [SCHOOL_LAT, SCHOOL_LNG]], {
        color: 'blue', dashArray: '5, 10', weight: 2
    }).addTo(map);
};

onMounted(() => {
    // Inisialisasi peta di koordinat awal anak
    map = L.map('edit-map').setView([form.latitude, form.longitude], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Marker Sekolah
    L.circleMarker([SCHOOL_LAT, SCHOOL_LNG], {
        color: 'red', fillColor: 'red', fillOpacity: 1, radius: 8
    }).addTo(map).bindPopup("<b>Sekolah</b>");

    // Marker Anak (Bisa digeser/drag)
    userMarker = L.marker([form.latitude, form.longitude], { draggable: true })
        .addTo(map)
        .bindPopup("<b>Geser pin ini ke lokasi persis (Gang/Blok)</b>")
        .openPopup();

    userMarker.on('dragend', function (e) {
        const position = userMarker.getLatLng();
        updateMapAndData(position.lat, position.lng);
        map.panTo(position);
    });

    // Gambar garis awal
    updateMapAndData(form.latitude, form.longitude);
});

const submit = () => {
    form.put(route('location.update', props.student.id));
};

const formatRupiah = (angka) => {
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(angka);
};
</script>

<template>
    <Head title="Update Titik Jemput" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Update Lokasi Jemputan: {{ student.name }}</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow border border-gray-200 flex flex-col md:flex-row gap-6">
                    
                    <div class="md:w-1/3 flex flex-col gap-4">
                        <div class="bg-blue-50 p-4 rounded border border-blue-200">
                            <h3 class="font-bold text-blue-800 mb-2">Instruksi:</h3>
                            <ul class="text-sm text-blue-700 list-disc pl-4 space-y-1">
                                <li>Geser pin pada peta ke posisi rumah Anda setepat mungkin.</li>
                                <li>Tuliskan detail patokan rumah di kolom alamat.</li>
                            </ul>
                        </div>

                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Detail Alamat Lengkap</label>
                            <textarea 
                                v-model="form.address_text" 
                                rows="3" 
                                class="w-full border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" 
                                placeholder="Contoh: Jl. Mawar Gg. Kancil No. 5 (Rumah pagar hitam depan warung Bu Siti)"
                                required
                            ></textarea>
                            <p class="text-xs text-gray-500 mt-1">Alamat ini akan dibaca oleh supir armada.</p>
                        </div>

                        <div class="bg-gray-100 p-4 rounded border mt-auto">
                            <p class="text-sm text-gray-500">Estimasi Jarak: <b class="text-gray-800">{{ form.distance.toFixed(2) }} KM</b></p>
                            <p class="text-sm text-gray-500 mb-2">Tarif Baru: <b class="text-red-600">{{ formatRupiah(form.price) }}</b></p>
                            
                            <button 
                                type="submit" 
                                :disabled="form.processing"
                                class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded transition disabled:opacity-50"
                            >
                                {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </button>
                        </div>
                    </div>

                    <div class="md:w-2/3">
                        <div id="edit-map" class="h-[500px] w-full rounded border-2 border-gray-300 z-0"></div>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>