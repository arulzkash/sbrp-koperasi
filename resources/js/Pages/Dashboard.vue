<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    children: Array,
});

const formatRupiah = (angka) => {
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(angka);
};

// Fungsi pembantu untuk menerjemahkan tipe layanan
const getServiceName = (type) => {
    if (type === 'pickup_only') return 'Berangkat Saja';
    if (type === 'dropoff_only') return 'Pulang Saja';
    return 'Antar & Jemput (PP)';
};
</script>

<template>
    <Head title="Dashboard Orang Tua" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Dashboard Orang Tua</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-gray-900 font-bold text-lg border-b">
                        Selamat datang, {{ $page.props.auth.user.name }}!
                    </div>
                    <div class="p-6 bg-blue-50 text-blue-800 text-sm">
                        Ini adalah portal pantauan untuk layanan antar-jemput anak Anda.
                    </div>
                </div>

                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-700">Status Langganan Siswa</h3>

                    <div v-if="children.length > 0" class="text-xs text-gray-500">
                        Kelola lokasi per siswa pada kartu di bawah.
                    </div>
                </div>


                <div v-if="children.length === 0" class="bg-white p-8 text-center rounded-lg shadow-sm border border-gray-200">
                    <p class="text-gray-500 mb-4">Anda belum mendaftarkan lokasi jemputan anak.</p>
                    <Link href="/" class="text-blue-600 underline font-bold">Klik di sini untuk mencari tarif dan mendaftar.</Link>
                </div>

                <div v-else class="grid grid-cols-1 gap-6">
                    <div v-for="child in children" :key="child.id" class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                        
                        <div class="bg-gray-50 p-4 border-b flex justify-between items-start">
                            <div>
                                <h4 class="font-bold text-lg text-gray-800">{{ child.name }}</h4>
                                <p class="text-xs font-semibold text-gray-600 mt-1">
                                    <span class="bg-gray-200 px-2 py-1 rounded text-gray-700 mr-2">{{ child.school_level }}</span>
                                    <span class="bg-gray-200 px-2 py-1 rounded text-gray-700 mr-2">Kelas: {{ child.class_room || '-' }}</span>
                                    <span class="bg-blue-100 text-blue-800 font-bold px-2 py-1 rounded">{{ getServiceName(child.service_type) }}</span>
                                </p>

                                <div class="mt-3">
                                    <Link
                                        v-if="child.status !== 'active'"
                                        :href="route('location.edit', child.id)"
                                        class="inline-flex items-center rounded-md bg-yellow-500 px-3 py-2 text-xs font-bold text-white shadow transition hover:bg-yellow-600"
                                    >
                                        ✏️ Update Titik Jemput
                                    </Link>

                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-xs italic text-gray-500"
                                    >
                                        Rute terkunci. Hubungi admin untuk ubah lokasi.
                                    </span>
                                </div>
                            </div>

                            <span v-if="child.payment_status === 'unpaid'" class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-bold border border-red-200">
                                BELUM BAYAR
                            </span>
                            <span v-else class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold border border-green-200">
                                LUNAS
                            </span>
                        </div>


                        <div class="p-4">
                            <div class="mb-4 bg-gray-50 p-3 rounded border border-gray-100 flex justify-between items-center">
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">📍 Alamat Titik Jemput:</p>
                                    <p class="font-semibold text-gray-800 text-sm">{{ child.address_text }}</p>
                                    <p class="text-xs text-blue-600 mt-1">[Lihat Koordinat: {{ child.latitude }}, {{ child.longitude }}]</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Biaya / Bln:</p>
                                    <p class="font-bold text-red-600 text-lg">{{ formatRupiah(child.price_per_month) }}</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <p class="text-sm text-gray-500 mb-1">Status Layanan:</p>
                                <div class="flex items-center gap-2">
                                    <span
                                        v-if="child.payment_status === 'unpaid'"
                                        class="text-red-600 font-bold text-sm"
                                    >
                                        ⛔ Menunggu Verifikasi Pembayaran
                                    </span>

                                    <span
                                        v-else-if="child.status === 'registered'"
                                        class="text-yellow-600 font-bold text-sm"
                                    >
                                        ⏳ Lunas, menunggu generate rute admin
                                    </span>

                                    <span
                                        v-else-if="child.status === 'active'"
                                        class="text-green-600 font-bold text-sm"
                                    >
                                        ✅ Armada sudah aktif
                                    </span>

                                    <span
                                        v-else
                                        class="text-gray-500 font-bold text-sm"
                                    >
                                        • Status belum tersedia
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                
                                <div v-if="['full', 'pickup_only'].includes(child.service_type)" class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <p class="text-xs text-blue-700 font-bold uppercase mb-2">☀️ Armada Pagi (Berangkat)</p>
                                    <div v-if="child.morning_fleet">
                                        <p class="text-lg font-bold text-gray-800">🚐 {{ child.morning_fleet.name }}</p>
                                        <p class="text-sm text-gray-700 mt-1">Supir: <b>{{ child.morning_fleet.driver_name || '-' }}</b></p>
                                        <p class="text-xs text-gray-600 mt-2 bg-white inline-block px-2 py-1 rounded border">Estimasi Urutan Jemput: <b>Ke-{{ child.morning_route_order }}</b></p>
                                    </div>
                                    <div v-else class="py-4 text-center">
                                        <p class="text-xs text-gray-500 italic">Armada belum ditugaskan.</p>
                                    </div>
                                </div>

                                <div v-if="['full', 'dropoff_only'].includes(child.service_type)" class="p-4 bg-orange-50 border border-orange-200 rounded-lg">
                                    <p class="text-xs text-orange-700 font-bold uppercase mb-2">🌙 Armada Pulang (Sesi {{ child.session_out.substring(0, 5) }})</p>
                                    <div v-if="child.afternoon_fleet">
                                        <p class="text-lg font-bold text-gray-800">🚐 {{ child.afternoon_fleet.name }}</p>
                                        <p class="text-sm text-gray-700 mt-1">Supir: <b>{{ child.afternoon_fleet.driver_name || '-' }}</b></p>
                                        <p class="text-xs text-gray-600 mt-2 bg-white inline-block px-2 py-1 rounded border">Estimasi Urutan Antar: <b>Ke-{{ child.afternoon_route_order }}</b></p>
                                    </div>
                                    <div v-else class="py-4 text-center">
                                        <p class="text-xs text-gray-500 italic">Armada belum ditugaskan.</p>
                                    </div>
                                </div>

                            </div>
                        </div>
                        
                        <div class="p-4 bg-gray-50 border-t flex flex-col gap-2" v-if="child.payment_status === 'unpaid'">
                            <p class="text-xs text-gray-600 text-center mb-1">
                                Silakan lunasi tagihan agar lokasi anak Anda dapat diproses ke dalam rute armada.
                            </p>
                            <a 
                                :href="'https://wa.me/6281234567890?text=Halo Admin Keuangan Koperasi, saya ingin konfirmasi pembayaran antar-jemput untuk anak saya: ' + child.name" 
                                target="_blank"
                                class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded transition text-center flex items-center justify-center gap-2"
                            >
                                💬 Konfirmasi Bayar via WhatsApp
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>