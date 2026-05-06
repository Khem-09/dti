const ctx = document.getElementById('trendChart').getContext('2d');

const trendChart = new Chart(ctx, {
    type: 'line',
    data: {
        // X-Axis Labels (Weeks)
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5'],
        datasets: [
            {
                label: 'Highest Price',
                data: [60, 85, 25, 60, 50], 
                borderColor: '#A7D89D', 
                backgroundColor: '#A7D89D',
                borderWidth: 2,
                tension: 0.1, 
                pointBackgroundColor: '#fff',
                pointBorderColor: '#A7D89D',
                pointRadius: 4
            },
            {
                label: 'Lowest Price',
                data: [40, 60, 30, 10, 20], 
                borderColor: '#F4A261', 
                backgroundColor: '#F4A261',
                borderWidth: 2,
                tension: 0.1, 
                pointBackgroundColor: '#fff',
                pointBorderColor: '#F4A261',
                pointRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false 
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                title: {
                    display: true,
                    text: 'Prices',
                    color: '#333',
                    font: { weight: 'bold' }
                },
                grid: { color: '#bbb' }
            },
            x: {
                grid: { color: '#bbb' }
            }
        }
    }
});