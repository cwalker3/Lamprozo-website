document.addEventListener('DOMContentLoaded', function () {

    let bookAnAppointmentBtn = document.querySelector('#book-an-appointment-btn');
    bookAnAppointmentBtn.addEventListener('pointerup', bookAnAppointment);

    saveCalendarData(calData.calendar);
    renderCalendar('calendar-container');

    const typesSelect = document.querySelector('#book-an-appointment-type');
    if (typesSelect && calData.booking_types) {
        calData.booking_types.forEach(type => {
            const option = document.createElement('option');
            option.value = type.type_title;
            option.textContent = type.type_title;
            typesSelect.appendChild(option);
        });
    }

});