export const estimatePricing = async (payload) => {
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const response = await fetch(route('pricing.estimate'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error('Failed to estimate pricing');
    }

    const data = await response.json();

    return {
        monthlyPP: data.monthly_pp ?? 0,
        monthlyOneWay: data.monthly_one_way ?? 0,
        servicePrice: data.service_price ?? 0,
        serviceType: data.service_type ?? 'full',
        estimatedTripFare: data.estimated_trip_fare ?? 0,
        distanceCharge: data.distance_charge ?? 0,
        durationCharge: data.duration_charge ?? 0,
    };
};
