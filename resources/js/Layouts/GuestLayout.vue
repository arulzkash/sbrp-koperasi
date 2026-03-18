<script setup>
import { computed } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import { Head, Link, usePage } from "@inertiajs/vue3";

const page = usePage();

const isRegisterPage = computed(() => route().current("register"));

const outerMaxWidth = computed(() =>
    isRegisterPage.value ? "max-w-6xl" : "max-w-2xl",
);

const contentPadding = computed(() =>
    isRegisterPage.value
        ? "px-4 py-5 sm:px-6 lg:px-8 lg:py-8"
        : "px-4 py-5 sm:px-6 sm:py-6",
);

const heroContent = computed(() => {
    if (route().current("register")) {
        return {
            title: "Pendaftaran Layanan Orang Tua",
            description:
                "Lengkapi data akun, siswa, dan layanan untuk melanjutkan proses verifikasi.",
            note: "Data yang diisi di halaman ini akan dipakai untuk verifikasi pembayaran dan penentuan rute armada.",
        };
    }

    if (route().current("login")) {
        return {
            title: "Masuk ke Portal Orang Tua",
            description:
                "Pantau status layanan, pembayaran, dan penugasan armada dari satu tempat.",
            note: null,
        };
    }

    if (route().current("password.request")) {
        return {
            title: "Reset Password",
            description:
                "Masukkan email akun Anda untuk menerima tautan reset password.",
            note: null,
        };
    }

    if (route().current("password.reset")) {
        return {
            title: "Buat Password Baru",
            description:
                "Gunakan password baru untuk mengakses kembali portal layanan.",
            note: null,
        };
    }

    if (route().current("password.confirm")) {
        return {
            title: "Konfirmasi Password",
            description:
                "Konfirmasi password Anda untuk melanjutkan ke halaman yang dilindungi.",
            note: null,
        };
    }

    if (route().current("verification.notice")) {
        return {
            title: "Verifikasi Email",
            description:
                "Periksa email Anda untuk menyelesaikan aktivasi akun.",
            note: null,
        };
    }

    return {
        title: "Portal Layanan Orang Tua",
        description:
            "Akses layanan antar-jemput siswa dan pantau status pendaftaran.",
        note: null,
    };
});
</script>

<template>
    <Head title="Akses Orang Tua" />

    <div class="min-h-screen bg-slate-50">
        <div
            class="mx-auto px-4 py-6 sm:px-6 lg:px-8 lg:py-8"
            :class="outerMaxWidth"
        >
            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <div
                    class="bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-5 text-white sm:px-8 sm:py-6"
                >
                    <div
                        class="flex flex-col gap-4"
                        :class="
                            isRegisterPage
                                ? 'sm:flex-row sm:items-center sm:justify-between'
                                : 'sm:flex-row sm:items-center sm:gap-4'
                        "
                    >
                        <div class="flex items-center gap-3">
                            <Link
                                href="/"
                                class="shrink-0 rounded-xl bg-white/10 p-2 ring-1 ring-white/20"
                            >
                                <ApplicationLogo
                                    class="h-10 w-10 fill-current text-white"
                                />
                            </Link>

                            <div>
                                <p
                                    class="text-[10px] font-semibold uppercase tracking-[0.2em] text-blue-100"
                                >
                                    Antar Jemput Siswa
                                </p>
                                <h1 class="mt-1 text-lg font-bold sm:text-xl">
                                    {{ heroContent.title }}
                                </h1>
                                <p
                                    class="mt-1 text-xs text-blue-100 sm:text-sm"
                                >
                                    {{ heroContent.description }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="heroContent.note"
                            class="rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-xs leading-5 text-blue-50 sm:max-w-xs"
                        >
                            {{ heroContent.note }}
                        </div>
                    </div>
                </div>

                <div :class="contentPadding">
                    <div class="mb-4">
                        <Link
                            href="/"
                            class="text-sm font-medium text-blue-600 transition hover:text-blue-700"
                        >
                            ← Kembali ke Halaman Estimasi
                        </Link>
                    </div>

                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
