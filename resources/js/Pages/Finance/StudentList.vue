<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    students: Array,
});

const unpaidCount = computed(() => {
    return props.students.filter((student) => student.payment_status === "unpaid").length;
});

const paidCount = computed(() => {
    return props.students.filter((student) => student.payment_status === "paid").length;
});

const activeCount = computed(() => {
    return props.students.filter((student) => student.status === "active").length;
});

// Fungsi Format Rupiah
const formatRupiah = (amount) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(amount);
};

// Fungsi konfirmasi pembayaran
const confirmPayment = (student) => {
    if (
        confirm(
            `Apakah Anda yakin ingin mengonfirmasi pembayaran untuk siswa: ${student.name}?`,
        )
    ) {
        router.put(
            route("finance.students.pay", student.id),
            {},
            {
                preserveScroll: true,
            },
        );
    }
};
</script>

<template>
    <Head title="Manajemen Keuangan" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Admin Keuangan: Daftar Pembayaran Siswa
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 bg-white border-b border-gray-200">
                        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <h3 class="text-lg font-bold text-gray-700">
                                Daftar Pendaftaran Langganan
                            </h3>

                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-bold text-red-700">
                                    Belum Bayar: {{ unpaidCount }}
                                </span>
                                <span class="rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-bold text-green-700">
                                    Lunas: {{ paidCount }}
                                </span>
                                <span class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                                    Aktif: {{ activeCount }}
                                </span>
                            </div>
                        </div>


                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr
                                        class="bg-gray-100 border-b-2 border-gray-200"
                                    >
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600"
                                        >
                                            No
                                        </th>
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600"
                                        >
                                            Nama Siswa (Orang Tua)
                                        </th>
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600"
                                        >
                                            Jarak / Harga
                                        </th>
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600"
                                        >
                                            Status Operasi
                                        </th>
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600 text-center"
                                        >
                                            Status Pembayaran
                                        </th>
                                        <th
                                            class="p-3 font-semibold text-sm text-gray-600 text-center"
                                        >
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(student, index) in students"
                                        :key="student.id"
                                        class="border-b hover:bg-gray-50"
                                    >
                                        <td class="p-3 text-sm text-gray-700">
                                            {{ index + 1 }}
                                        </td>
                                        <td class="p-3">
                                            <p class="font-bold text-gray-800">
                                                {{ student.name }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                Orang Tua:
                                                {{
                                                    student.user?.name ||
                                                    "Tidak ada data"
                                                }}
                                            </p>
                                            <p
                                                class="text-xs text-blue-500 mt-1 cursor-help"
                                                :title="student.address_text"
                                            >
                                                📍 Lihat Alamat
                                            </p>
                                        </td>
                                        <td class="p-3">
                                            <p
                                                class="text-sm font-semibold text-gray-700"
                                            >
                                                {{
                                                    (
                                                        student.distance_to_school_meters /
                                                        1000
                                                    ).toFixed(2)
                                                }}
                                                KM
                                            </p>
                                            <p
                                                class="text-xs text-red-600 font-bold"
                                            >
                                                {{
                                                    formatRupiah(
                                                        student.price_per_month,
                                                    )
                                                }}
                                            </p>
                                        </td>
                                        <td class="p-3">
                                            <span
                                                v-if="student.payment_status === 'unpaid'"
                                                class="text-xs font-bold text-red-600 bg-red-100 px-2 py-1 rounded"
                                            >
                                                Menunggu Verifikasi
                                            </span>
                                            <span
                                                v-else-if="student.status === 'registered'"
                                                class="text-xs font-bold text-yellow-600 bg-yellow-100 px-2 py-1 rounded"
                                            >
                                                Lunas, Menunggu Rute
                                            </span>
                                            <span
                                                v-else-if="student.status === 'active'"
                                                class="text-xs font-bold text-green-600 bg-green-100 px-2 py-1 rounded"
                                            >
                                                Aktif
                                            </span>
                                            <span
                                                v-else
                                                class="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-1 rounded"
                                            >
                                                Belum Diproses
                                            </span>
                                        </td>

                                        <td class="p-3 text-center">
                                            <span
                                                v-if="
                                                    student.payment_status ===
                                                    'unpaid'
                                                "
                                                class="text-xs font-bold text-red-600 bg-red-100 px-2 py-1 rounded-full border border-red-200"
                                                >BELUM BAYAR</span
                                            >
                                            <span
                                                v-else
                                                class="text-xs font-bold text-green-600 bg-green-100 px-2 py-1 rounded-full border border-green-200"
                                                >LUNAS</span
                                            >
                                        </td>
                                        <td class="p-3 text-center">
                                            <button
                                                v-if="
                                                    student.payment_status ===
                                                    'unpaid'
                                                "
                                                @click="confirmPayment(student)"
                                                class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-1.5 px-3 rounded shadow transition"
                                            >
                                                Konfirmasi Lunas
                                            </button>
                                            <span
                                                v-else
                                                class="text-xs text-gray-400 italic"
                                                >Selesai</span
                                            >
                                        </td>
                                    </tr>
                                    <tr v-if="students.length === 0">
                                        <td
                                            colspan="6"
                                            class="p-6 text-center text-gray-500"
                                        >
                                            Belum ada data pendaftaran siswa.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
