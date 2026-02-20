<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link } from "@inertiajs/vue3";

defineProps({
    children: Array,
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
    <Head title="Dashboard Orang Tua" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard Orang Tua
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div
                    class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6"
                >
                    <div class="p-6 text-gray-900 font-bold text-lg border-b">
                        Selamat datang, {{ $page.props.auth.user.name }}!
                    </div>
                    <div class="p-6 bg-blue-50 text-blue-800 text-sm">
                        Ini adalah portal pantauan untuk layanan antar-jemput
                        anak Anda.
                    </div>
                </div>

                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-700">
                        Status Langganan Siswa
                    </h3>

                    <div v-if="children.length > 0">
                        <Link 
                            v-if="children[0].status !== 'active'" 
                            :href="route('location.edit', children[0].id)"
                            class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded text-sm font-bold shadow transition"
                        >
                            ✏️ Update Titik Jemput
                        </Link>
                        <span v-else class="text-xs text-gray-500 italic bg-gray-100 px-3 py-2 rounded border">
                            Rute terkunci. Hubungi admin untuk ubah lokasi.
                        </span>
                    </div>
                </div>

                <div
                    v-if="children.length === 0"
                    class="bg-white p-8 text-center rounded-lg shadow-sm border border-gray-200"
                >
                    <p class="text-gray-500 mb-4">
                        Anda belum mendaftarkan lokasi jemputan anak.
                    </p>
                    <Link href="/" class="text-blue-600 underline font-bold"
                        >Klik di sini untuk mencari tarif dan mendaftar.</Link
                    >
                </div>

                <div v-else class="grid grid-cols-1 gap-6">
                    <div
                        v-for="child in children"
                        :key="child.id"
                        class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200"
                    >
                        <div
                            class="bg-gray-50 p-4 border-b flex justify-between items-center"
                        >
                            <div>
                                <h4 class="font-bold text-lg text-gray-800">
                                    {{ child.name }}
                                </h4>
                                <p
                                    class="text-xs font-semibold text-gray-500 uppercase tracking-wide"
                                >
                                    Jenjang: {{ child.school_level }}
                                </p>
                            </div>

                            <span
                                v-if="child.payment_status === 'unpaid'"
                                class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-bold border border-red-200"
                            >
                                BELUM BAYAR
                            </span>
                            <span
                                v-else
                                class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold border border-green-200"
                            >
                                LUNAS
                            </span>
                        </div>

                        <div class="p-4">
                            <div class="mb-4 bg-gray-50 p-3 rounded border border-gray-100">
                                <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">📍 Alamat Titik Jemput:</p>
                                <p class="font-semibold text-gray-800 text-sm">{{ child.address_text }}</p>
                                <p class="text-xs text-blue-600 mt-1 cursor-pointer hover:underline">
                                    [Lihat Koordinat: {{ child.latitude }}, {{ child.longitude }}]
                                </p>
                            </div>

                            <div class="mb-4">
                                <p class="text-sm text-gray-500 mb-1">Status Operasional:</p>
                                <div class="flex items-center gap-2">
                                    <span v-if="child.status === 'registered'" class="text-yellow-600 font-bold text-sm">⏳ Menunggu Penentuan Rute</span>
                                    <span v-else-if="child.status === 'active'" class="text-green-600 font-bold text-sm">✅ Aktif Dijemput</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4 text-sm mb-4 bg-gray-50 p-3 rounded">
                                <div>
                                    <p class="text-gray-500">Jarak ke Sekolah</p>
                                    <p class="font-bold">{{ (child.distance_to_school_meters / 1000).toFixed(2) }} KM</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Tagihan per Bulan</p>
                                    <p class="font-bold text-red-600">{{ formatRupiah(child.price_per_month) }}</p>
                                </div>
                            </div>

                            <div v-if="child.fleet" class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-xs text-blue-600 font-bold uppercase mb-1">Informasi Armada</p>
                                <p class="text-sm font-semibold text-gray-800">🚐 {{ child.fleet.name }}</p>
                                <p class="text-xs text-gray-600 mt-1">Estimasi Urutan Jemput: <b>Ke-{{ child.route_order }}</b></p>
                            </div>
                            <div v-else class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded-lg text-center">
                                <p class="text-xs text-gray-500">Mobil armada belum ditugaskan (Tunggu Admin).</p>
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
