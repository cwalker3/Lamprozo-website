function renderCalendar(containerId, year = null, month = null, day = null) {
    let isAdmin, nonce;
    nonce = calData.nonce;
    isAdmin = false;
    if ( calData.isAdmin && isCorrectAdminPage() ) isAdmin = true;

    const appointmentSessionData = getAppointmentSessionData();
    let isAppointmentRequested = false;
    let appointmentData = null;
    for (blockId in appointmentSessionData) {
        if (appointmentSessionData[blockId]['request_flag'] === '1') isAppointmentRequested = true;
        appointmentData = appointmentSessionData[blockId];
    }

    const today = new Date();
    let currentYear = year || today.getFullYear();
    let currentMonth = (month !== null && month !== undefined) ? month : today.getMonth();
    const currentDay = (day !== null && day !== undefined) ? day : today.getDate();
    const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    const totalCells = firstDay + daysInMonth;
    const numberOfWeeks = Math.ceil(totalCells / 7);

    const container = document.getElementById(containerId);
    if (!container) {
        console.error(`Container with id "${containerId}" not found.`);
        return;
    }

    container.innerHTML = '';
    
    if (calData.admin_settings && !isAdmin) {
        const authorization_token = calData.admin_settings.authorization_token;
        const urlParams = new URLSearchParams(window.location.search);
        const urlToken = urlParams.get('token');

        if (!calData.admin_settings.public_calendar && authorization_token !== urlToken) {
            container.innerHTML = `<h2>Unauthorized Access.</h2>`;
            return;
        }
    }

    const title = document.createElement('h2');
    title.id = 'calendar-title';
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    title.textContent = `${monthNames[currentMonth]} ${currentYear}`;
    container.appendChild(title);

    const navContainer = document.createElement('div');
    navContainer.classList.add('calendar-nav');

    const prevButton = document.createElement('button');
    prevButton.textContent = '< Prev';
    prevButton.classList.add('nav-button');
    prevButton.addEventListener('click', () => {
        currentMonth--;
        if (currentMonth < 0) {
            currentMonth = 11;
            currentYear--;
        }
        renderCalendar(containerId, currentYear, currentMonth, currentDay);
    });

    const nextButton = document.createElement('button');
    nextButton.textContent = 'Next >';
    nextButton.classList.add('nav-button');
    nextButton.addEventListener('click', () => {
        currentMonth++;
        if (currentMonth > 11) {
            currentMonth = 0;
            currentYear++;
        }
        renderCalendar(containerId, currentYear, currentMonth, currentDay);
    });

    navContainer.appendChild(prevButton);
    navContainer.appendChild(nextButton);
    container.appendChild(navContainer);

    prevButton.style.opacity = '1';
    prevButton.style.pointerEvents = 'auto';
    if (
        currentMonth === today.getMonth() &&
        currentYear === today.getFullYear()
    ) {
        prevButton.style.opacity = '0.5';
        prevButton.style.pointerEvents = 'none';
    }

    const tableContainer = document.createElement('div');
    tableContainer.classList.add('calendar-container');

    const table = document.createElement('table');
    table.classList.add('calendar');

    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    daysOfWeek.forEach(dayName => {
        const th = document.createElement('th');
        th.textContent = dayName;
        headerRow.appendChild(th);
    });

    thead.appendChild(headerRow);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');

    let dayOfMonth = 1;

    for (let week = 0; week < numberOfWeeks; week++) {
        const row = document.createElement('tr');

        for (let day = 0; day < 7; day++) {
            const cell = document.createElement('td');
            cell.style.color = 'black';
            const dateNumber = week * 7 + day - firstDay + 1;

            if (dateNumber > 0 && dateNumber <= daysInMonth) {
                cell.textContent = dateNumber;
                if (!isAdmin) disableCell(cell);
                cell.id = `date-${dayOfMonth}-${currentMonth}-${currentYear}`;

                let blockIdSubStr = `${dateNumber}-${currentMonth}-${currentYear}`;
                const allKeys = Object.keys(appointmentSessionData);
                const matchingKeys = allKeys.filter(key => key.startsWith(`${blockIdSubStr}@`));
                const isAppointmentDay = matchingKeys.length > 0;

                if (isAppointmentDay) {
                    if (isAdmin) {
                        cell.style.backgroundColor = 'var(--darkGrey)';
                        cell.style.color = 'white';
                    }

                    if (!isAppointmentRequested) enableCell(cell);

                    const appointmentData = matchingKeys.map(key => appointmentSessionData[key]);
                    const totalApts = appointmentData.length;
                    let removedApts = 0;
                    appointmentData.forEach(appointment => {
                       if (appointment.remove_flag === "1") removedApts++;
                       let aptRequestFlag = appointment['request_flag'];
                       let requestConfirmed = appointment['request_confirmed'];
                       if (aptRequestFlag === '1') {
                        enableCell(cell);
                        cell.style.backgroundColor = 'var(--darkGreen)';
                        if (isAdmin) {
                            cell.style.backgroundColor = 'var(--darkGreen)';
                            if (requestConfirmed === '1') cell.style.backgroundColor = 'var(--lightGreen)';
                        }
                        return;
                       }
                    });

                    if (removedApts === totalApts) {
                        cell.style.backgroundColor = '';
                        cell.style.color = 'black';
                    }
                }

                cell.addEventListener('pointerup', (event) => {
                    event.stopImmediatePropagation();
                    setTimeout(() => {
                        renderSchedule(containerId, currentYear, currentMonth, dateNumber, isAdmin);
                    }, 100);                    
                });
                dayOfMonth++;
            } else {
                cell.textContent = '';
                cell.style.opacity = '0.5';
                cell.style.pointerEvents = 'none';
            }

            row.appendChild(cell);
        }

        tbody.appendChild(row);
    }

    table.appendChild(tbody);
    container.appendChild(table);

    const bookAppointmentBtn = document.querySelector('#book-an-appointment-btn');
    for (blockId in appointmentSessionData) {
        
    }
    if (appointmentSessionData['appointment-request-made']) {
        if (!isAdmin) {
            bookAppointmentBtn.style.opacity = '0.3';
            bookAppointmentBtn.style.pointerEvents = 'none'; 
        }
    }
}

function disableCell(cell) {
    cell.style.opacity = '0.1';
    cell.style.pointerEvents = 'none';
}

function enableCell(cell) {
    cell.style.opacity = '1';
    cell.style.pointerEvents = 'auto';
}

async function renderSchedule(containerId, year, month, day, isAdmin) {
    const appointmentSessionData = getAppointmentSessionData();
    const bookAppointmentBtn = document.querySelector('#book-an-appointment-btn');
    
    const container = document.getElementById(containerId);
    if (!container) {
        console.error(`Container with id "${containerId}" not found.`);
        return;
    }

    container.innerHTML = '';

    const scheduleTitle = document.createElement('h2');
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    scheduleTitle.textContent = `Schedule for ${monthNames[month]} ${day}, ${year}`;
    container.appendChild(scheduleTitle);

    const backButton = document.createElement('button');
    backButton.textContent = 'Back to Calendar';
    backButton.classList.add('back-button');
    backButton.addEventListener('click', () => {
        renderCalendar(containerId, year, month);
    });
    container.appendChild(backButton);

    const scheduleTable = document.createElement('table');
    scheduleTable.classList.add('schedule');

    const tbody = document.createElement('tbody');
    
    const bookingsAdminData = calData.admin_settings;
    let startHour = 10, endHour = 16;
    if (bookingsAdminData && !isAdmin) {
        startHour = bookingsAdminData.beginning_hour;
        endHour = bookingsAdminData.end_hour-1;
    }
    if (isAdmin) {
        let data = getBookingsAdminData();
        startHour = data.settings.beginning_hour;
        endHour = data.settings.end_hour-1;
    }

    for (let hour = startHour; hour <= endHour; hour++) {
        let isAppointmentHour = false;
        let blockId = `${day}-${month}-${year}@${hour}`;
        if ( appointmentSessionData.hasOwnProperty(blockId) ) isAppointmentHour = true;
        let isAppointmentRequest = false;
        if (isAppointmentHour) if (appointmentSessionData[blockId]['request_flag'] === "1") isAppointmentRequest = true;
        let aptConfirmed = false;
        if (isAppointmentHour) if (appointmentSessionData[blockId]['request_confirmed'] === "1") aptConfirmed = true;

        const row = document.createElement('tr');

        const timeCell = document.createElement('td');
        const period = hour >= 12 ? 'PM' : 'AM';
        let displayHour = hour > 12 ? hour - 12 : hour;
        let displayHourAfter = displayHour+1;
        if (displayHour === 12) displayHourAfter = 1;
        if (isAppointmentHour) {
            displayHour = appointmentSessionData[blockId].start_time;
            displayHour = convertMilitaryToCommon(displayHour).replace(/(am|pm)/, '');
            displayHourAfter = appointmentSessionData[blockId].end_time;
            displayHourAfter = convertMilitaryToCommon(displayHourAfter).replace(/(am|pm)/, '');
        }
        timeCell.innerHTML = `<div id="start-time-${hour}">${displayHour} ${period}-</div>
                              <div id="end-time-${hour}">${displayHourAfter} ${period}</div>`;
        
        timeCell.classList.add('time-cell');

        const eventCell = document.createElement('td');
        eventCell.textContent = '';
        eventCell.classList.add('event-cell');
        eventCell.id = `event-${hour}`;

        if (isAdmin || isAppointmentHour) {
            row.appendChild(timeCell);
            row.appendChild(eventCell);
        }

        eventCell.innerHTML = 'Reserve';

        if (!isAdmin && isAppointmentHour && !aptConfirmed) {
            eventCell.addEventListener('pointerup', (event)=>{
                let id = event.currentTarget.id;
                toggleAppointmentRequest(id, eventCell, day, month, year, appointmentSessionData);
            });
        }
        
        if (isAdmin) {
            handleAdminTimeBlock(eventCell, 'close');
            
            timeCell.innerHTML = await getTimeDropDowns(startHour, endHour, hour);
            setUpDropDownEventListeners(timeCell, day, month, year, hour, appointmentSessionData);
            if (!isAppointmentRequest) {
                eventCell.addEventListener('pointerup', (event)=>{
                    let id = event.currentTarget.id;
                    toggleOpenHourBlock(id, day, month, year, isAdmin);
                });
            }
        }

        tbody.appendChild(row);
    }

    scheduleTable.appendChild(tbody);
    container.appendChild(scheduleTable);

    // Refactored code
    Object.keys(appointmentSessionData).forEach((blockId) => {
        const [date, hour] = blockId.split('@');
        const dateId = `${day}-${month}-${year}`;
        const isAppointmentRequest = appointmentSessionData[blockId]['request_flag'] === '1';
        const aptConfirmed = appointmentSessionData[blockId]['request_confirmed'] === '1';
        const isRemoved = appointmentSessionData[blockId]['remove_flag'] === '1';
    
        if (date !== dateId) return;
    
        const eventEle = document.querySelector(`#event-${hour}`);
    
        if (!isAdmin && isAppointmentRequest) {
        eventEle.style.outline = '3px solid var(--outlineRed)';
        }
    
        if (isAdmin && !isRemoved) {
            handleAdminTimeBlock(eventEle, 'open');
            
            if (isAppointmentRequest && !aptConfirmed) {
                handleAppointmentRequest(eventEle, blockId, appointmentSessionData[blockId]);
            }

            if (aptConfirmed) {
                const cssFriendlyBlockId = blockId.replace(/@/, '_');
                eventEle.innerHTML = `<div class="appointment-request">
                                        <div id="apt-title-${cssFriendlyBlockId}">${appointmentSessionData[blockId].type_name} Appointment scheduled with: <br>
                                        ${appointmentSessionData[blockId].first_name} ${appointmentSessionData[blockId].last_name}</div>
                                        <div class="apt-safety" id="apt-title-cancel-${cssFriendlyBlockId}">Are you sure you want to <br>cancel this appointment?</div>
                                        <div class="apt-buttons">
                                            <button id="cancel-apt-${cssFriendlyBlockId}" style="grid-column: 1/-1;">Cancel</button>
                                            <button class="apt-safety" id="apt-confirm-cancel-yes-${cssFriendlyBlockId}">Yes</button>
                                            <button class="apt-safety" id="apt-confirm-cancel-no-${cssFriendlyBlockId}">No</button>
                                        </div>
                                      </div>`;
                eventEle.style.backgroundColor = 'var(--lightGreen)';
                eventEle.style.cursor = 'auto';
                const aptTitle = document.querySelector(`#apt-title-${cssFriendlyBlockId}`);
                const aptCancelTitle = document.querySelector(`#apt-title-cancel-${cssFriendlyBlockId}`);
                const cancelAptBtn = document.querySelector(`#cancel-apt-${cssFriendlyBlockId}`);
                const cancelAptYesBtn = document.querySelector(`#apt-confirm-cancel-yes-${cssFriendlyBlockId}`);
                const cancelAptNoBtn = document.querySelector(`#apt-confirm-cancel-no-${cssFriendlyBlockId}`);
                cancelAptBtn.addEventListener('pointerup', ()=>{
                    aptTitle.style.display = 'none';
                    aptCancelTitle.style.display = 'block';
                    cancelAptBtn.style.display = 'none';
                    cancelAptYesBtn.style.display = 'block';
                    cancelAptNoBtn.style.display = 'block';
                });
                cancelAptYesBtn.addEventListener('pointerup', ()=>{
                    handleAppointment(blockId, cssFriendlyBlockId, 'cancel', appointmentSessionData[blockId]);
                });
                cancelAptNoBtn.addEventListener('pointerup', ()=>{
                    aptTitle.style.display = 'block';
                    aptCancelTitle.style.display = 'none';
                    cancelAptBtn.style.display = 'block';
                    cancelAptYesBtn.style.display = 'none';
                    cancelAptNoBtn.style.display = 'none';
                });
            }
        }
    }); 

    if (appointmentSessionData['appointment-request-made']) {
        if (!isAdmin) {
            bookAppointmentBtn.style.opacity = '0.3';
            bookAppointmentBtn.style.pointerEvents = 'none';
        }
    }
}

function handleAppointmentRequest(eventEle, blockId, appointmentData) {
    const cssFriendlyBlockId = blockId.replace(/@/, '_');
    const { first_name, last_name, message } = appointmentData;
    const name = `${first_name} ${last_name}`;
    const type = appointmentData.type_name;

    eventEle.innerHTML = getAppointmentRequestHTML(cssFriendlyBlockId, name, type);
    eventEle.style.backgroundColor = 'var(--darkGreen)';
    eventEle.style.cursor = 'auto';

    const appointmentRequest = document.querySelector(`#apt-request-${cssFriendlyBlockId}`);
    const aptInfoText = appointmentRequest.querySelector('.apt-info');
    const aptConfirmConfirmText = appointmentRequest.querySelector('.apt-confirm-confirm');
    const aptConfirmCancelText = appointmentRequest.querySelector('.apt-confirm-cancel');

    const confirmAptBtn = appointmentRequest.querySelector(`#apt-confirm-btn-${cssFriendlyBlockId}`);
    const cancelAptBtn = appointmentRequest.querySelector(`#apt-cancel-btn-${cssFriendlyBlockId}`);
    const confirmYesAptBtn = appointmentRequest.querySelector(`#apt-confirm-yes-btn-${cssFriendlyBlockId}`);
    const confirmNoAptBtn = appointmentRequest.querySelector(`#apt-confirm-no-btn-${cssFriendlyBlockId}`);
    const cancelYesAptBtn = appointmentRequest.querySelector(`#apt-cancel-yes-btn-${cssFriendlyBlockId}`);
    const cancelNoAptBtn = appointmentRequest.querySelector(`#apt-cancel-no-btn-${cssFriendlyBlockId}`);

    const toggleElements = (showElements, hideElements) => {
        showElements.forEach((ele) => (ele.style.display = 'block'));
        hideElements.forEach((ele) => (ele.style.display = 'none'));
    };

    confirmAptBtn.addEventListener('pointerup', () => {
    toggleElements(
        [aptConfirmConfirmText, confirmYesAptBtn, confirmNoAptBtn],
        [aptInfoText, confirmAptBtn, cancelAptBtn]
    );
    });

    cancelAptBtn.addEventListener('pointerup', () => {
    toggleElements(
        [aptConfirmCancelText, cancelYesAptBtn, cancelNoAptBtn],
        [aptInfoText, confirmAptBtn, cancelAptBtn]
    );
    });

    confirmYesAptBtn.addEventListener('pointerup', () => {
        handleAppointment(blockId, cssFriendlyBlockId, 'confirm', appointmentData);
    });

    confirmNoAptBtn.addEventListener('pointerup', () => {
    toggleElements(
        [aptInfoText, confirmAptBtn, cancelAptBtn],
        [aptConfirmConfirmText, confirmYesAptBtn, confirmNoAptBtn]
    );
    });

    cancelNoAptBtn.addEventListener('pointerup', () => {
    toggleElements(
        [aptInfoText, confirmAptBtn, cancelAptBtn],
        [aptConfirmCancelText, cancelYesAptBtn, cancelNoAptBtn]
    );
    });

    cancelYesAptBtn.addEventListener('pointerup', () => {
        handleAppointment(blockId, cssFriendlyBlockId, 'cancel', appointmentData);
    });
}

function getAppointmentRequestHTML(cssFriendlyBlockId, name, type) {
    return `
    <div class="appointment-request" id="apt-request-${cssFriendlyBlockId}">
        <div class="apt-info">${type} Appointment Request: ${name}</div>
        <div class="apt-confirm-confirm">Are you sure you want to confirm?</div>
        <div class="apt-confirm-cancel">Are you sure you want to cancel?</div>
        <div class="apt-buttons">
            <button id="apt-confirm-btn-${cssFriendlyBlockId}">Confirm</button>
            <button id="apt-cancel-btn-${cssFriendlyBlockId}">Cancel</button>
            <button class="apt-safety" id="apt-confirm-yes-btn-${cssFriendlyBlockId}">Yes</button>
            <button class="apt-safety" id="apt-confirm-no-btn-${cssFriendlyBlockId}">No</button>
            <button class="apt-safety" id="apt-cancel-yes-btn-${cssFriendlyBlockId}">Yes</button>
            <button class="apt-safety" id="apt-cancel-no-btn-${cssFriendlyBlockId}">No</button>
        </div>
    </div>
    `;
}

function handleAppointment(blockId, cssFriendlyBlockId, type, appointmentData) {
    const appointmentSessionData = getAppointmentSessionData();
    const aptEle = document.querySelector(`#apt-request-${cssFriendlyBlockId}`);
    const appointmentHour = getAppointmentHour(blockId);
    const eventCell = document.querySelector(`#event-${appointmentHour}`);

    if (type === 'confirm') requestConfirmed = 1;
    if (type === 'cancel') requestConfirmed = 0;

    let aptData = JSON.stringify({
        id: appointmentData.id,
        type: type,
        request_confirmed: requestConfirmed,
        email: appointmentSessionData[blockId].email,
        first_name: appointmentSessionData[blockId].first_name,
        last_name: appointmentSessionData[blockId].last_name,
        phone: appointmentSessionData[blockId].phone,
        message: appointmentSessionData[blockId].message,
        start_time: appointmentSessionData[blockId].start_time,
        end_time: appointmentSessionData[blockId].end_time,
        type_name: appointmentSessionData[blockId].type_name
    });

    fetch(`${bookingsData.api_url}handle-appointment`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': bookingsData.nonce
        },
        body: aptData
    })
    .then(response => response.json())
    .then(function (data) {
        if (data.success) {
            if (data.type === 'confirm') {
                aptEle.innerHTML = data.message;
                eventCell.style.backgroundColor = 'var(--lightGreen)';
                appointmentSessionData[blockId].request_confirmed = "1";
            }
            if (data.type === 'cancel') {
                appointmentSessionData[blockId].first_name = "";
                appointmentSessionData[blockId].last_name = "";
                appointmentSessionData[blockId].email = "";
                appointmentSessionData[blockId].phone = "";
                appointmentSessionData[blockId].type_name = "";
                appointmentSessionData[blockId].message = "";
                appointmentSessionData[blockId].request_flag = "0";
                appointmentSessionData[blockId].request_confirmed = "0";
                let day = appointmentSessionData[blockId]['day_number'];
                let month = appointmentSessionData[blockId]['month_number'];
                let year = appointmentSessionData[blockId]['year_number'];
                eventCell.style.cursor = 'pointer';
                eventCell.addEventListener('pointerup', ()=>{
                    toggleOpenHourBlock(eventCell.id, day, month, year, true);
                });
                handleAdminTimeBlock(eventCell, 'open');
            }
            window.sessionStorage.setItem('eyh-appointment-data', JSON.stringify(appointmentSessionData));
        }
    })
    .catch(function (error) {
        console.error('Error:', error);
    });
}

function toggleAppointmentRequest(id, eventCell, day, month, year, appointmentData) {
    const bookAppointmentBtn = document.querySelector('#book-an-appointment-btn');
    let fname = "", lname = "", email = "", phone = ""
    fname = document.querySelector('#book-an-appointment-form-fname').value;
    lname = document.querySelector('#book-an-appointment-form-lname').value;
    email = document.querySelector('#book-an-appointment-form-email').value;
    phone = document.querySelector('#book-an-appointment-form-phone').value;

    let errorText = document.querySelector('#error-txt');
    if ( (fname === '' || lname === '' || email === '') ) {
        errorText.innerHTML = 'Name and email required.';
        window.scrollTo({top: 0, behavior: 'smooth'});
        return;
    }

    let isSelected = false;
    if (eventCell.style.outline === '3px solid var(--outlineRed)') isSelected = true;
    if (!isSelected && appointmentData['appointment-request-start']) return;
    let hour = id.replace(/[a-z]+\-/, '');
    let blockId = `${day}-${month}-${year}@${hour}`;
    if (isSelected) {
        appointmentData[blockId].request_flag = '0';
        appointmentData['appointment-request-start'] = false;
        eventCell.style.outline = 'none';
        eventCell.innerHTML = 'Reserve';
        bookAppointmentBtn.style.opacity = '0.3';
        bookAppointmentBtn.style.pointerEvents = 'none';
    }
    if (!isSelected) {
        appointmentData[blockId].request_flag = '1';
        appointmentData['appointment-request-start'] = true;
        eventCell.style.outline = '3px solid var(--outlineRed)';
        eventCell.innerHTML = 'Reserved';
        bookAppointmentBtn.style.opacity = '1';
        bookAppointmentBtn.style.pointerEvents = 'auto';
    }
    window.sessionStorage.setItem('eyh-appointment-data', JSON.stringify(appointmentData));
}

function toggleOpenHourBlock(id, day, month, year, isAdmin) {

    const appointmentSessionData = getAppointmentSessionData();
    if (appointmentSessionData['appointment-request-made']) return;
    
    let hourBlock = document.querySelector(`#${id}`);
    let hour = parseInt( id.replace(/event-/, '') );

    let blockId = `${day}-${month}-${year}@${hour}`;
    let isSelected = false;
    let isAdminSelected;
    if (hourBlock.style.outline === '3px solid var(--outlineRed)') isSelected = true;
    if (hourBlock.style.opacity === '0.5') isAdminSelected = false;
    if (hourBlock.style.opacity === '1') isAdminSelected = true;

    let hasId = false;
    let aptId = null;
    if (appointmentSessionData[blockId]) if (appointmentSessionData[blockId].hasOwnProperty('id')) {
        hasId = true;
        aptId = appointmentSessionData[blockId]['id'];
    }

    if (isSelected || isAdminSelected) {
        hourBlock.style.outline = '';
        appointmentSessionData[blockId]['remove_flag'] = "1";
        if (!hasId) delete appointmentSessionData[blockId];
        sessionStorage.setItem( 'eyh-appointment-data', JSON.stringify(appointmentSessionData) );
        handleAdminTimeBlock(hourBlock, 'close');
        enableSaveCalendar();
        return;
    }
    if (!isAdmin) hourBlock.style.outline = '3px solid var(--outlineRed)';
    const startTimeEle = document.querySelector(`#start-time-${hour}`);
    const endTimeEle = document.querySelector(`#end-time-${hour}`);
    let startTime = '', endTime = '';
    if (startTimeEle) startTime = isAdmin ? startTimeEle.value : startTimeEle.innerText;
    if (endTimeEle) endTime = isAdmin ? endTimeEle.value : endTimeEle.innerText;

    appointmentSessionData[blockId] = {
        first_name: "",
        last_name: "",
        email: "",
        phone: "",
        day_number: day,
        month_number: month,
        year_number: year,
        start_time: startTime,
        end_time: endTime,
        request_flag: '0',
        remove_flag: '0'
    };

    if (hasId) appointmentSessionData[blockId]['id'] = aptId;

    sessionStorage.setItem( 'eyh-appointment-data', JSON.stringify(appointmentSessionData) );
    handleAdminTimeBlock(hourBlock, 'open');
    enableSaveCalendar();
}

function handleAdminTimeBlock(hourBlock, type) {
    switch (type) {
        case 'close':
            hourBlock.style.backgroundColor = '';
            hourBlock.style.color = 'black';
            hourBlock.style.opacity = '0.5'
            hourBlock.style.outline = 'none';
            hourBlock.innerHTML = 'Closed';
            break;
        case 'open':
            hourBlock.style.backgroundColor = 'var(--lightBlue)';
            hourBlock.style.color = 'white';
            hourBlock.style.outline = 'none';
            hourBlock.style.opacity = '1';
            hourBlock.innerHTML = 'Open';
            break;
    }
}

async function getTimeDropDowns(startHour, endHour, hour) {
    let selects = ['start-time', 'end-time'];
    let dropDownsHTML = '';
    let selected;

    selects.forEach(selectId=>{
        dropDownsHTML += `<select class="book-an-appointment-time-select" id="${selectId}-${hour}">`;
        for (let time = startHour; time <= endHour; time++) {
            let times = [`${time}:00`, `${time}:30`];
            let to = '';
            if (selectId === 'start-time') to = ' to';
            times.forEach(timeStr=>{
                if (selectId === 'start-time' && time > hour) return;
                if (selectId === 'start-time' && time < hour) return;
                selected = '';
                if (selectId === 'start-time' &&
                    !timeStr.includes(':30') &&
                    parseInt(time) === parseInt(hour)) selected = ' selected ';
                if (selectId === 'end-time' &&
                    !timeStr.includes(':30') &&
                    parseInt(time) === parseInt(hour+1)) selected = ' selected ';
                dropDownsHTML += `<option value="${timeStr}"${selected}>${convertMilitaryToCommon(timeStr)}${to}</option>`;
            });
            if (time === endHour && selectId === 'end-time') {
                if ( parseInt(time) === parseInt(hour) ) selected = ' selected ';
                let timeStr = `${endHour+1}:00`;
                dropDownsHTML += `<option value="${endHour+1}:00" ${selected}>${convertMilitaryToCommon(timeStr)}</option>`;
            }
        }
        dropDownsHTML += `</select>`;
    });

    return dropDownsHTML;

}

function setUpDropDownEventListeners(timeCell, day, month, year, hour, appointmentData) {
    if ( isOpenHourBlock(day, month, year, hour, appointmentData) ) {
        const selects = timeCell.querySelectorAll('select');
        let startTimeSelect = selects[0];
        let endTimeSelect = selects[1];
        const blockId = `${day}-${month}-${year}@${hour}`;
        const aptStartTime = appointmentData[blockId].start_time;
        const aptEndTime = appointmentData[blockId].end_time;
        selects.forEach(select=>{
            select.selectedIndex = -1;
            for (let i = 0; i < select.options.length; i++) {
                let timeValue = select.options[i].value;
                if (select.id.includes('start-time') && timeValue === aptStartTime) select.selectedIndex = i;
                if (select.id.includes('end-time') && timeValue === aptEndTime) select.selectedIndex = i;
            }
        });

        startTimeSelect.addEventListener('change', ()=>{
            updateTime('start-time', startTimeSelect.value, blockId);
        });
        endTimeSelect.addEventListener('change', ()=>{
            updateTime('end-time', endTimeSelect.value, blockId);
        });
    }
}

function isOpenHourBlock(day, month, year, hour, appointmentData) {
    for (blockId in appointmentData) {
        if (blockId === `${day}-${month}-${year}@${hour}`) return true;
    }
    return false;
}

function updateTime(type, value, blockId) {
    const appointmentSessionData = getAppointmentSessionData();
    if (type === 'start-time') appointmentSessionData[blockId].start_time = value;
    if (type === 'end-time') appointmentSessionData[blockId].end_time = value;

    window.sessionStorage.setItem( 'eyh-appointment-data', JSON.stringify(appointmentSessionData) );
    if ( appointmentSessionData.hasOwnProperty(blockId) ) {
        if (appointmentSessionData[blockId].remove_flag !== "1") enableSaveCalendar();
    }
}

function getAppointmentDate(appointmentSessionData) {
    if (Object.keys(appointmentSessionData).length === 0) return {day: 0, month: 0, year: 0};
    for (data in appointmentSessionData) {
        let dataSplit = data.split('@');
        let date = dataSplit[0];
        let day = parseInt(date.match(/^[0-9]+/));
        let month, year
        let findMonth = date.match(/^[0-9]+-([0-9]+)/);
        if (findMonth) month = parseInt(findMonth[1]);
        let findYear = date.match(/-([0-9]+)$/);
        if (findYear) year = parseInt(findYear[1]);

        return {
            day: day,
            month: month,
            year: year
        };
    }
}

function getAppointmentHour(blockId) {
    let dataSplit = blockId.split('@');
    let hour = parseInt(dataSplit[1]);
    return hour;
}

function isAppointmentHour(hour) {
    const appointmentSessionData = getAppointmentSessionData();
    for (data in appointmentSessionData) {
        let aptHour = getAppointmentHour(data);
        if (aptHour === hour) return true
    }
    return false;
}

function bookAnAppointment() {
    const bookAnAppointmentBtn = document.querySelector('#book-an-appointment-btn');
    let fname = document.querySelector('#book-an-appointment-form-fname').value;
    let lname = document.querySelector('#book-an-appointment-form-lname').value;
    let email = document.querySelector('#book-an-appointment-form-email').value;
    let phone = document.querySelector('#book-an-appointment-form-phone').value;
    let type = document.querySelector('#book-an-appointment-type').value;
    const appointmentMessageEle = document.querySelector('#book-an-appointment-form-message');
    const appointmentMessage = appointmentMessageEle.value;
    const appointmentSessionData = getAppointmentSessionData();
    const appointmentDate = getAppointmentDate(appointmentSessionData);
    let aptReturnData = {};

    aptReturnData['msg'] = appointmentMessage;
    for (data in appointmentSessionData) {
        if (data === true) continue;
        blockId = data;
        if (appointmentSessionData[blockId]['request_flag'] !== '1') continue;
        aptReturnData['id'] = appointmentSessionData[blockId]['id'];
        aptReturnData['first_name'] = fname;
        aptReturnData['last_name'] = lname;
        aptReturnData['email'] = email;
        aptReturnData['type'] = type;
        aptReturnData['phone'] = phone;
        aptReturnData['day'] = appointmentDate.day;
        aptReturnData['month'] = appointmentDate.month;
        aptReturnData['year'] = appointmentDate.year;
        aptReturnData['hour'] = getAppointmentHour(blockId);
        aptReturnData['start-time'] = appointmentSessionData[blockId]['start_time'];
        aptReturnData['end-time'] = appointmentSessionData[blockId]['end_time'];
        aptReturnData['request_flag'] = appointmentSessionData[blockId]['request_flag'];
    }
    
    fetch(`${myApi.api_url}request-appointment`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': myApi.nonce
            },
            body: JSON.stringify(aptReturnData)
        })
        .then(response => response.json())
        .then(function (data) {
            if (data.success) {
                bookAnAppointmentBtn.innerHTML = data.message;
                bookAnAppointmentBtn.style.cursor = 'auto';
                bookAnAppointmentBtn.removeEventListener('pointerup', bookAnAppointment);

                appointmentSessionData['appointment-request-made'] = true;
                sessionStorage.setItem('eyh-appointment-data', JSON.stringify(appointmentSessionData));
            }
            if (!data.success) console.error(data.message);
        })
        .catch(function (error) {
            console.error('Error:', error);
        });
}

function isCorrectAdminPage() {
    const pathname = window.location.pathname;
    const searchParams = new URLSearchParams(window.location.search);
    return (
        pathname.endsWith('/wp-admin/admin.php') &&
        searchParams.get('page') === 'my-bookings'
    );
}

function convertMilitaryToCommon(militaryTime) {
    const [militaryHourStr, minutes] = militaryTime.split(':');
    
    let militaryHour = parseInt(militaryHourStr, 10);
    
    const period = militaryHour >= 12 ? 'pm' : 'am';
    
    let commonHour = militaryHour % 12;
    if (commonHour === 0) {
        commonHour = 12;
    }
    
    return `${commonHour}:${minutes} ${period}`;
}

function getBlockId(data) {
    let day_number = data.day_number;
    let month_number = data.month_number;
    let year_number = data.year_number;
    let hour = parseInt( data.start_time.match(/^[0-9]+/) );
    return `${day_number}-${month_number}-${year_number}@${hour}`;
}

function saveCalendar() {
    const appointmentSessionData = getAppointmentSessionData();
    const appointmentData = JSON.stringify(appointmentSessionData);
    const loader = document.querySelector('.loader');
    loader.style.visibility = 'visible';
    fetch(`${bookingsData.api_url}save-calendar`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': bookingsData.nonce
        },
        body: appointmentData
    })
    .then(response => response.json())
    .then(function (data) {
        if (data.success) {
            let inserted = JSON.parse(data.records_inserted);
            let deleted = JSON.parse(data.records_deleted);

            inserted.forEach(insertData=>{
                let blockId = getBlockId(insertData);
                appointmentSessionData[blockId].id = insertData.id;
                let hour = getAppointmentHour(blockId);
                let startTimeSelect = document.querySelector(`#start-time-${hour}`);
                let timeCell = startTimeSelect.parentElement;
                let day = insertData.day_number;
                let month = insertData.month_number;
                let year = insertData.year_number;
                setUpDropDownEventListeners(timeCell, day, month, year, hour, appointmentSessionData);
            });
            
            deleted.forEach(data=>{
                let blockId = getBlockId(data);
                delete appointmentSessionData[blockId];
            });

            window.sessionStorage.setItem( 'eyh-appointment-data', JSON.stringify(appointmentSessionData) );
            disableSaveCalendar();
            loader.style.visibility = 'hidden';
        }
        if (!data.success) console.error(data.message);
    })
    .catch(function (error) {
        console.error('Error:', error);
    });
}

function saveCalendarData(blocks, isAdmin = false) {
    let appointmentData = {};
    if (blocks === null) {
        window.sessionStorage.setItem('eyh-appointment-data', '{}');
        return;
    }
    blocks.forEach(block=>{
        let startHour = block.start_time.match(/^[0-9]+/);
        let blockId = `${block.day_number}-${block.month_number}-${block.year_number}@${startHour}`;
        appointmentData[blockId] = block;
        if (!isAdmin) {
            delete appointmentData[blockId]['remove_flag'];
            delete appointmentData[blockId]['email'];
            delete appointmentData[blockId]['first_name'];
            delete appointmentData[blockId]['last_name'];
            delete appointmentData[blockId]['phone'];
        }
    });

    window.sessionStorage.setItem('eyh-appointment-data', JSON.stringify(appointmentData));
}

function disableSaveCalendar() {
    const saveCalendarBtn = document.querySelector('#save-calendar-btn');
    saveCalendarBtn.style.opacity = '0.3';
    saveCalendarBtn.style.pointerEvents = 'none';
    saveCalendarBtn.style.cursor = 'none';
}

function enableSaveCalendar() {
    const saveCalendarBtn = document.querySelector('#save-calendar-btn');
    saveCalendarBtn.style.opacity = '1';
    saveCalendarBtn.style.pointerEvents = 'auto';
    saveCalendarBtn.style.cursor = 'pointer';
}

function createAppointmentSession() {
    const sessionStorageObj = window.sessionStorage;

    let sessionData = sessionStorageObj.getItem('eyh-appointment-data');

    if (sessionData === null) {
        const initialData = {};
        sessionStorageObj.setItem('eyh-appointment-data', JSON.stringify(initialData));
        sessionData = initialData;
    }
}

function getAppointmentSessionData() {
    const sessionStorage = window.sessionStorage;
    const appointmentSessionData = JSON.parse( sessionStorage.getItem('eyh-appointment-data') );
    return appointmentSessionData;
}

createAppointmentSession();
