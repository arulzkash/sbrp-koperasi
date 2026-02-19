<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Tangkap Data dari URL (Query Parameters)
const props = defineProps({
    status: String,
});

// Ambil parameter dari URL browser
const params = new URLSearchParams(window.location.search);

const form = useForm({
    name: '', // Nama Ortu
    email: '',
    password: '',
    password_confirmation: '',
    
    // Data Tambahan untuk Siswa
    student_name: '',
    school_level: 'SD', // Default
    
    // Data Lokasi (Otomatis terisi dari Landing Page)
    latitude: params.get('lat') || '',
    longitude: params.get('lng') || '',
    distance: params.get('distance') || '',
    price_estimasi: params.get('price') || '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// Format Rupiah buat tampilan
const formatRupiah = (angka) => {
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(angka);
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <div v-if="form.latitude" class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
            <h3 class="font-bold mb-1">📍 Lokasi Jemputan Terdeteksi</h3>
            <p>Jarak: <b>{{ parseFloat(form.distance).toFixed(2) }} KM</b></p>
            <p>Estimasi Biaya: <b>{{ formatRupiah(form.price_estimasi) }} / bulan</b></p>
            <p class="text-xs mt-2 text-gray-500">*Lokasi sudah dikunci. Silakan lengkapi data diri.</p>
        </div>

        <form @submit.prevent="submit">
            
            <div class="border-b pb-4 mb-4">
                <h2 class="text-lg font-semibold text-gray-700 mb-3">Data Orang Tua</h2>
                <div>
                    <InputLabel for="name" value="Nama Lengkap Orang Tua" />
                    <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="name" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="email" value="Email" />
                    <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div class="mt-4">
                    <InputLabel for="password" value="Password" />
                    <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="mt-4">
                    <InputLabel for="password_confirmation" value="Konfirmasi Password" />
                    <TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <div class="mb-6" v-if="form.latitude">
                <h2 class="text-lg font-semibold text-gray-700 mb-3">Data Siswa</h2>
                <div>
                    <InputLabel for="student_name" value="Nama Siswa" />
                    <TextInput id="student_name" type="text" class="mt-1 block w-full" v-model="form.student_name" required placeholder="Nama anak yang akan dijemput" />
                    <InputError class="mt-2" :message="form.errors.student_name" />
                </div>

                <div class="mt-4">
                    <InputLabel for="school_level" value="Jenjang Sekolah" />
                    <select id="school_level" v-model="form.school_level" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="TK">TK (Taman Kanak-kanak)</option>
                        <option value="SD">SD (Sekolah Dasar)</option>
                        <option value="SMP">SMP (Sekolah Menengah Pertama)</option>
                    </select>
                </div>
            </div>

            <div v-else class="mb-4 text-red-500 text-sm italic">
                ⚠️ Anda belum memilih lokasi rumah. <Link href="/" class="underline font-bold">Klik di sini untuk pilih lokasi di Peta.</Link>
            </div>

            <div class="flex items-center justify-end mt-4">
                <Link :href="route('login')" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Sudah terdaftar?
                </Link>

                <PrimaryButton class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Daftar & Langganan
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>