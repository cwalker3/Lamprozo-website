<h1>Request an Appointment</h1>

<?=$postContent?>

<div class="book-an-appointment-form">
    <div id="error-txt"></div>
    <input type="text" placeholder="First Name*" id="book-an-appointment-form-fname">
    <input type="text" placeholder="Last Name*" id="book-an-appointment-form-lname">
    <input type="text" placeholder="Email*" id="book-an-appointment-form-email">
    <input type="text" placeholder="Optional Phone" id="book-an-appointment-form-phone">

    <textarea id="book-an-appointment-form-message" placeholder="Optional Message"></textarea>

    <select id="book-an-appointment-type">
        <option value="General">General Appointment</option>
    </select>

    <h2>Schedule</h2>

    <div id="calendar-container"></div>

    <div id="book-an-appointment-btn"><button>Request Appointment</button></div>
</div>