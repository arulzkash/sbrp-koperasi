<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: String,
});

const params = new URLSearchParams(window.location.search);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    
    student_name: '',
    school_level: 'SD',
    class_room: '',
    
    // DATA BARU: Sesi & Layanan
    service_type: 'full', // Default: Antar-Jemput
    session_in: '06:30',  // Default jam masuk serentak
    session_out: '13:00', // Default jam pulang
    
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
            <p>Estimasi Biaya Dasar: <b>{{ formatRupiah(form.price_estimasi) }} / bln</b></p>
            <p class="text-xs mt-2 text-gray-500 italic">*Biaya final mungkin menyesuaikan jenis layanan (Satu Arah / Pulang-Pergi) setelah konfirmasi dengan admin.</p>
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
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <div>
                        <InputLabel for="password" value="Password" />
                        <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="new-password" />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>
                    <div>
                        <InputLabel for="password_confirmation" value="Konfirmasi Password" />
                        <TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" required autocomplete="new-password" />
                        <InputError class="mt-2" :message="form.errors.password_confirmation" />
                    </div>
                </div>
            </div>

            <div class="mb-6" v-if="form.latitude">
                <h2 class="text-lg font-semibold text-gray-700 mb-3">Data Siswa & Jadwal</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <InputLabel for="student_name" value="Nama Siswa" />
                        <TextInput id="student_name" type="text" class="mt-1 block w-full" v-model="form.student_name" required placeholder="Nama lengkap anak" />
                    </div>
                    <div>
                        <InputLabel for="school_level" value="Jenjang" />
                        <select id="school_level" v-model="form.school_level" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="TK">TK</option>
                            <option value="SD">SD</option>
                            <option value="SMP">SMP</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="class_room" value="Kelas (Detail)" />
                        <TextInput id="class_room" type="text" class="mt-1 block w-full" v-model="form.class_room" required placeholder="Contoh: 4B, 7A, B1" />
                    </div>
                </div>

                <div class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                    <InputLabel value="Jenis Layanan Armada" class="mb-2 font-bold text-gray-700" />
                    <div class="flex gap-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" v-model="form.service_type" value="full" class="text-blue-600 focus:ring-blue-500">
                            <span class="ml-2 text-sm">Antar & Jemput (PP)</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" v-model="form.service_type" value="pickup_only" class="text-blue-600 focus:ring-blue-500">
                            <span class="ml-2 text-sm">Berangkat Saja</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" v-model="form.service_type" value="dropoff_only" class="text-blue-600 focus:ring-blue-500">
                            <span class="ml-2 text-sm">Pulang Saja</span>
                        </label>
                    </div>
                </div>

                <div class="mt-4" v-if="form.service_type === 'full' || form.service_type === 'dropoff_only'">
                    <InputLabel for="session_out" value="Sesi Kepulangan (Waktu Standar)" />
                    <select id="session_out" v-model="form.session_out" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="13:00">Sesi 1 (13:00 WIB)</option>
                        <option value="13:30">Sesi 2 (13:30 WIB)</option>
                        <option value="14:30">Sesi 3 (14:30 WIB)</option>
                        <option value="15:30">Sesi 4 (15:30 WIB)</option>
                        <option value="15:45">Sesi 5 (15:45 WIB)</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Pilih jadwal terdekat dengan bel pulang sekolah anak Anda.</p>
                </div>
            </div>

            <div v-else class="mb-4 text-red-500 text-sm italic">
                ⚠️ Anda belum memilih lokasi rumah. <Link href="/" class="underline font-bold">Klik di sini untuk pilih lokasi di Peta.</Link>
            </div>

            <div class="flex items-center justify-end mt-6 pt-4 border-t">
                <Link :href="route('login')" class="underline text-sm text-gray-600 hover:text-gray-900 mr-4">
                    Sudah punya akun?
                </Link>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Daftar & Langganan
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>