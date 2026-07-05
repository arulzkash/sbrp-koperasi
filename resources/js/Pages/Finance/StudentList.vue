<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    students: Object,
    filters: Object,
    stats: Object,
});

const filterForm = useForm({
    search: props.filters?.search || "",
    payment_status: props.filters?.payment_status || "",
    service_status: props.filters?.service_status || "",
});

const studentRows = computed(() => props.students?.data || []);

const unpaidCount = computed(() => props.stats?.unpaid || 0);
const paidCount = computed(() => props.stats?.paid || 0);
const activeCount = computed(() => props.stats?.active || 0);

const formatRupiah = (amount) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(amount);
};

const applyFilters = () => {
    router.get(
        route("finance.students"),
        {
            search: filterForm.search || undefined,
            payment_status: filterForm.payment_status || undefined,
            service_status: filterForm.service_status || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filterForm.search = "";
    filterForm.payment_status = "";
    filterForm.service_status = "";
    applyFilters();
};

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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-12">
            <div class="max-w-7xl mx-auto">
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

                        <div class="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                                <div class="md:col-span-2">
                                    <label class="mb-1 block text-xs font-bold uppercase text-slate-500">
                                        Cari Siswa / Orang Tua
                                    </label>
                                    <input
                                        v-model="filterForm.search"
                                        @keyup.enter="applyFilters"
                                        type="text"
                                        placeholder="Ketik nama siswa atau orang tua..."
                                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase text-slate-500">
                                        Status Pembayaran
                                    </label>
                                    <select
                                        v-model="filterForm.payment_status"
                                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option value="">Semua</option>
                                        <option value="unpaid">Belum Bayar</option>
                                        <option value="paid">Lunas</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase text-slate-500">
                                        Status Layanan
                                    </label>
                                    <select
                                        v-model="filterForm.service_status"
                                        class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option value="">Semua</option>
                                        <option value="pending_payment">Menunggu Verifikasi</option>
                                        <option value="waiting_route">Menunggu Rute</option>
                                        <option value="active">Aktif</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center">
                                <p class="text-xs text-slate-500">
                                    Tabel menampilkan hasil filter, ringkasan di atas menampilkan total keseluruhan.
                                </p>

                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button
                                        type="button"
                                        @click="resetFilters"
                                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        Reset
                                    </button>

                                    <button
                                        type="button"
                                        @click="applyFilters"
                                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
                                    >
                                        Terapkan Filter
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3 md:hidden">
                            <article
                                v-for="(student, index) in studentRows"
                                :key="`mobile-${student.id}`"
                                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-slate-400">
                                            #{{
                                                ((props.students.current_page - 1) * props.students.per_page) +
                                                index +
                                                1
                                            }}
                                        </p>
                                        <h4 class="mt-1 break-words text-sm font-bold text-slate-900">
                                            {{ student.name }}
                                        </h4>
                                        <p class="mt-1 break-words text-xs text-slate-500">
                                            Orang Tua: {{ student.user?.name || "Tidak ada data" }}
                                        </p>
                                    </div>

                                    <span
                                        v-if="student.payment_status === 'unpaid'"
                                        class="shrink-0 rounded-full border border-red-200 bg-red-100 px-2.5 py-1 text-[10px] font-bold text-red-700"
                                    >
                                        BELUM BAYAR
                                    </span>
                                    <span
                                        v-else
                                        class="shrink-0 rounded-full border border-green-200 bg-green-100 px-2.5 py-1 text-[10px] font-bold text-green-700"
                                    >
                                        LUNAS
                                    </span>
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                    <div class="rounded-lg bg-slate-50 p-3">
                                        <p class="font-semibold uppercase text-slate-400">Jarak / Harga</p>
                                        <p class="mt-1 font-semibold text-slate-700">
                                            {{ (student.distance_to_school_meters / 1000).toFixed(2) }} KM
                                        </p>
                                        <p class="font-bold text-red-600">
                                            {{ formatRupiah(student.price_per_month) }}
                                        </p>
                                    </div>

                                    <div class="rounded-lg bg-slate-50 p-3">
                                        <p class="font-semibold uppercase text-slate-400">Status Operasi</p>
                                        <span
                                            v-if="student.payment_status === 'unpaid'"
                                            class="mt-1 inline-flex rounded bg-red-100 px-2 py-1 font-bold text-red-600"
                                        >
                                            Menunggu Verifikasi
                                        </span>
                                        <span
                                            v-else-if="student.status === 'registered'"
                                            class="mt-1 inline-flex rounded bg-yellow-100 px-2 py-1 font-bold text-yellow-600"
                                        >
                                            Lunas, Menunggu Rute
                                        </span>
                                        <span
                                            v-else-if="student.status === 'active'"
                                            class="mt-1 inline-flex rounded bg-green-100 px-2 py-1 font-bold text-green-600"
                                        >
                                            Aktif
                                        </span>
                                        <span
                                            v-else
                                            class="mt-1 inline-flex rounded bg-gray-100 px-2 py-1 font-bold text-gray-500"
                                        >
                                            Belum Diproses
                                        </span>
                                    </div>
                                </div>

                                <p
                                    class="mt-3 break-words text-xs text-blue-500"
                                    :title="student.address_text"
                                >
                                    📍 {{ student.address_text || "Alamat belum tersedia" }}
                                </p>

                                <button
                                    v-if="student.payment_status === 'unpaid'"
                                    type="button"
                                    @click="confirmPayment(student)"
                                    class="mt-3 w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                >
                                    Konfirmasi Lunas
                                </button>
                                <p v-else class="mt-3 rounded-lg bg-slate-50 px-4 py-2 text-center text-xs italic text-slate-400">
                                    Selesai
                                </p>
                            </article>

                            <div
                                v-if="studentRows.length === 0"
                                class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500"
                            >
                                Tidak ada data yang cocok dengan filter saat ini.
                            </div>
                        </div>
                        <div class="hidden overflow-x-auto md:block">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-100 border-b-2 border-gray-200">
                                        <th class="p-3 font-semibold text-sm text-gray-600">
                                            No
                                        </th>
                                        <th class="p-3 font-semibold text-sm text-gray-600">
                                            Nama Siswa (Orang Tua)
                                        </th>
                                        <th class="p-3 font-semibold text-sm text-gray-600">
                                            Jarak / Harga
                                        </th>
                                        <th class="p-3 font-semibold text-sm text-gray-600">
                                            Status Operasi
                                        </th>
                                        <th class="p-3 font-semibold text-sm text-gray-600 text-center">
                                            Status Pembayaran
                                        </th>
                                        <th class="p-3 font-semibold text-sm text-gray-600 text-center">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(student, index) in studentRows"
                                        :key="student.id"
                                        class="border-b hover:bg-gray-50"
                                    >
                                        <td class="p-3 text-sm text-gray-700">
                                            {{
                                                ((props.students.current_page - 1) * props.students.per_page) +
                                                index +
                                                1
                                            }}
                                        </td>
                                        <td class="p-3">
                                            <p class="font-bold text-gray-800">
                                                {{ student.name }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                Orang Tua:
                                                {{ student.user?.name || "Tidak ada data" }}
                                            </p>
                                            <p
                                                class="text-xs text-blue-500 mt-1 cursor-help"
                                                :title="student.address_text"
                                            >
                                                📍 Lihat Alamat
                                            </p>
                                        </td>
                                        <td class="p-3">
                                            <p class="text-sm font-semibold text-gray-700">
                                                {{
                                                    (
                                                        student.distance_to_school_meters / 1000
                                                    ).toFixed(2)
                                                }}
                                                KM
                                            </p>
                                            <p class="text-xs text-red-600 font-bold">
                                                {{ formatRupiah(student.price_per_month) }}
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
                                                v-if="student.payment_status === 'unpaid'"
                                                class="text-xs font-bold text-red-600 bg-red-100 px-2 py-1 rounded-full border border-red-200"
                                            >
                                                BELUM BAYAR
                                            </span>
                                            <span
                                                v-else
                                                class="text-xs font-bold text-green-600 bg-green-100 px-2 py-1 rounded-full border border-green-200"
                                            >
                                                LUNAS
                                            </span>
                                        </td>

                                        <td class="p-3 text-center">
                                            <button
                                                v-if="student.payment_status === 'unpaid'"
                                                @click="confirmPayment(student)"
                                                class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-1.5 px-3 rounded shadow transition"
                                            >
                                                Konfirmasi Lunas
                                            </button>
                                            <span
                                                v-else
                                                class="text-xs text-gray-400 italic"
                                            >
                                                Selesai
                                            </span>
                                        </td>
                                    </tr>

                                    <tr v-if="studentRows.length === 0">
                                        <td
                                            colspan="6"
                                            class="p-6 text-center text-gray-500"
                                        >
                                            Tidak ada data yang cocok dengan filter saat ini.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div
                            v-if="props.students.links?.length > 3"
                            class="mt-5 flex flex-wrap items-center justify-center gap-2"
                        >
                            <component
                                :is="link.url ? Link : 'span'"
                                v-for="link in props.students.links"
                                :key="`${link.label}-${link.url || 'disabled'}`"
                                :href="link.url || undefined"
                                preserve-scroll
                                preserve-state
                                class="rounded-md px-3 py-2 text-sm"
                                :class="{
                                    'bg-blue-600 text-white font-semibold': link.active,
                                    'bg-white border border-slate-300 text-slate-700 hover:bg-slate-50': link.url && !link.active,
                                    'bg-slate-100 text-slate-400 cursor-not-allowed': !link.url,
                                }"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
