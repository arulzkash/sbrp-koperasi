<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const params = new URLSearchParams(window.location.search);

const distanceKmParam =
    params.get('distance_km') ||
    params.get('distance') ||
    '';

const priceParam =
    params.get('price') ||
    params.get('price_estimasi') ||
    '';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',

    student_name: '',
    school_level: 'SD',
    class_room: '',

    service_type: 'full',
    session_in: '06:30',
    session_out: '13:00',

    latitude: params.get('lat') || '',
    longitude: params.get('lng') || '',
    distance: distanceKmParam,
    price_estimasi: priceParam,
});

const hasPickupPoint = !!form.latitude && !!form.longitude;

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const formatRupiah = (angka) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(Number(angka || 0));
};

const formatDistanceKm = (angka) => {
    return Number(angka || 0).toFixed(2);
};
</script>

<template>
    <GuestLayout>
        <Head title="Daftar Layanan" />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div class="space-y-4 lg:col-span-4">
                <div
                    v-if="hasPickupPoint"
                    class="rounded-2xl border border-blue-200 bg-blue-50 p-5"
                >
                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-blue-700">
                        Ringkasan Lokasi
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-blue-900">
                        Lokasi Jemputan Terdeteksi
                    </h2>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-blue-100 bg-white p-3">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">
                                Jarak
                            </p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                {{ formatDistanceKm(form.distance) }} KM
                            </p>
                        </div>

                        <div class="rounded-xl border border-blue-100 bg-white p-3">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">
                                Estimasi
                            </p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                {{ formatRupiah(form.price_estimasi) }}
                            </p>
                        </div>
                    </div>

                    <p class="mt-4 text-xs leading-5 text-blue-800">
                        Estimasi ini menjadi dasar pendaftaran. Verifikasi akhir dilakukan admin.
                    </p>
                </div>

                <div
                    v-else
                    class="rounded-2xl border border-dashed border-red-200 bg-red-50 p-5"
                >
                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-red-700">
                        Titik Jemput Belum Ada
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-red-900">
                        Pilih lokasi dulu
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-red-800">
                        Pendaftaran butuh titik jemput agar estimasi dan verifikasi bisa diproses.
                    </p>

                    <Link
                        href="/"
                        class="mt-4 inline-flex items-center justify-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700"
                    >
                        Kembali ke Peta
                    </Link>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">
                        Alur
                    </p>
                    <div class="mt-3 space-y-2 text-sm text-slate-700">
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            1. Isi data orang tua dan siswa.
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            2. Admin verifikasi pembayaran.
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            3. Manager menentukan rute.
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-4 border-b border-slate-100 pb-4">
                            <h2 class="text-lg font-bold text-slate-900">
                                Data Orang Tua
                            </h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <InputLabel for="name" value="Nama Lengkap Orang Tua" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    class="mt-1 block w-full rounded-xl border-slate-300"
                                    v-model="form.name"
                                    required
                                    autofocus
                                    autocomplete="name"
                                />
                                <InputError class="mt-2" :message="form.errors.name" />
                            </div>

                            <div>
                                <InputLabel for="email" value="Email" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    class="mt-1 block w-full rounded-xl border-slate-300"
                                    v-model="form.email"
                                    required
                                    autocomplete="username"
                                />
                                <InputError class="mt-2" :message="form.errors.email" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <InputLabel for="password" value="Password" />
                                    <TextInput
                                        id="password"
                                        type="password"
                                        class="mt-1 block w-full rounded-xl border-slate-300"
                                        v-model="form.password"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError class="mt-2" :message="form.errors.password" />
                                </div>

                                <div>
                                    <InputLabel
                                        for="password_confirmation"
                                        value="Konfirmasi Password"
                                    />
                                    <TextInput
                                        id="password_confirmation"
                                        type="password"
                                        class="mt-1 block w-full rounded-xl border-slate-300"
                                        v-model="form.password_confirmation"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <InputError
                                        class="mt-2"
                                        :message="form.errors.password_confirmation"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        :class="{ 'opacity-70': !hasPickupPoint }"
                    >
                        <div class="mb-4 border-b border-slate-100 pb-4">
                            <h2 class="text-lg font-bold text-slate-900">
                                Data Siswa
                            </h2>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div>
                                <InputLabel for="student_name" value="Nama Siswa" />
                                <TextInput
                                    id="student_name"
                                    type="text"
                                    class="mt-1 block w-full rounded-xl border-slate-300"
                                    v-model="form.student_name"
                                    :required="hasPickupPoint"
                                    placeholder="Nama lengkap anak"
                                />
                                <InputError class="mt-2" :message="form.errors.student_name" />
                            </div>

                            <div>
                                <InputLabel for="school_level" value="Jenjang" />
                                <select
                                    id="school_level"
                                    v-model="form.school_level"
                                    class="mt-1 block w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="TK">TK</option>
                                    <option value="SD">SD</option>
                                    <option value="SMP">SMP</option>
                                </select>
                            </div>

                            <div>
                                <InputLabel for="class_room" value="Kelas" />
                                <TextInput
                                    id="class_room"
                                    type="text"
                                    class="mt-1 block w-full rounded-xl border-slate-300"
                                    v-model="form.class_room"
                                    :required="hasPickupPoint"
                                    placeholder="Contoh: 4B, 7A, A1"
                                />
                                <InputError class="mt-2" :message="form.errors.class_room" />
                            </div>
                        </div>
                    </div>

                    <div
                        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        :class="{ 'opacity-70': !hasPickupPoint }"
                    >
                        <div class="mb-4 border-b border-slate-100 pb-4">
                            <h2 class="text-lg font-bold text-slate-900">
                                Layanan
                            </h2>
                        </div>

                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <label class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            v-model="form.service_type"
                                            value="full"
                                            class="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="font-medium">Antar & Jemput</span>
                                    </div>
                                </label>

                                <label class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            v-model="form.service_type"
                                            value="pickup_only"
                                            class="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="font-medium">Berangkat Saja</span>
                                    </div>
                                </label>

                                <label class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            v-model="form.service_type"
                                            value="dropoff_only"
                                            class="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="font-medium">Pulang Saja</span>
                                    </div>
                                </label>
                            </div>

                            <div
                                v-if="form.service_type === 'full' || form.service_type === 'dropoff_only'"
                            >
                                <InputLabel for="session_out" value="Sesi Kepulangan" />
                                <select
                                    id="session_out"
                                    v-model="form.session_out"
                                    class="mt-1 block w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="13:00">Sesi 1 (13:00 WIB)</option>
                                    <option value="13:30">Sesi 2 (13:30 WIB)</option>
                                    <option value="14:30">Sesi 3 (14:30 WIB)</option>
                                    <option value="15:30">Sesi 4 (15:30 WIB)</option>
                                    <option value="15:45">Sesi 5 (15:45 WIB)</option>
                                </select>
                                <p class="mt-2 text-xs text-slate-500">
                                    Pilih sesi pulang yang paling mendekati jam selesai sekolah.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <Link
                            :href="route('login')"
                            class="text-sm text-slate-600 underline hover:text-slate-900"
                        >
                            Sudah punya akun?
                        </Link>

                        <PrimaryButton
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing || !hasPickupPoint"
                        >
                            Daftar dan Lanjut Verifikasi
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>
