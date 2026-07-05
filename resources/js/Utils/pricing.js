export const estimatePricing = async (payload) => {
    const params = new URLSearchParams();

    Object.entries(payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, value);
        }
    });

    const response = await fetch(`${route('pricing.estimate')}?${params.toString()}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
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
