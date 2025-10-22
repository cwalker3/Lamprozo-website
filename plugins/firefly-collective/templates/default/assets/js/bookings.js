const saveCalendarBtn = document.querySelector('#save-calendar-btn');
saveCalendarBtn.addEventListener('pointerup', saveCalendar);

saveCalendarData(calData.calendar, true);
renderCalendar('calendar-container');
renderBookingsAdmin();
saveBookingsAdminData();

const bookingsSettingsBtn = document.querySelector('#bookings-settings-btn');
bookingsSettingsBtn.addEventListener('pointerup', openBookingsSettings);
const bookingsSettingsCloseBtn = document.querySelector('#bookings-settings-close-btn');
const bookingsBackDrop = document.querySelector('#bookings-backdrop');
bookingsSettingsCloseBtn.addEventListener('pointerup', closeBookingsSettings);
bookingsBackDrop.addEventListener('pointerup', closeBookingsSettings);

function renderBookingsAdmin() {
    let pluginPath = bookingsData.plugin_path;
    let admin_settings = calData.admin_settings;
    let beginningHour, endHour, publicCalendar, authorizationToken
    if (admin_settings) {
        beginningHour = admin_settings.beginning_hour;
        endHour = admin_settings.end_hour;
        publicCalendar = admin_settings.public_calendar;
        authorizationToken = admin_settings.authorization_token;
    }
    else {
        bookingsAdminContainer.innerHTML = 'Could not retrieve admin settings';
        return;
    }

    const bookingsAdminContainer = document.querySelector('#bookings-admin');
    const beginningHourOptionsHTML = generateHourOptions(beginningHour);
    const endHourOptionsHTML = generateHourOptions(endHour);

    let bookingsTypesHTML = '';
    if (bookingsData.bookings_types) {
        bookingsData.bookings_types.forEach(type=>{
            bookingsTypesHTML += `<div class="booking-type" id="type-${type.type_id}">
                                    <span class="remove-type"><img src="${pluginPath}/images/close.webp"></span>
                                    <input type="text" value="${type.type_title}">
                                    <span class="undo-remove" id="undo-remove-${type.type_id}"><img src="${pluginPath}/images/add.webp"></span>
                                </div>`;
        });
    }

    bookingsAdminHTML = `<div id="bookings-settings-btn"><img src="${pluginPath}/images/settings.webp"></div>
                         <div id="bookings-settings-window">
                            <div id="bookings-settings-content">
                                <div id="bookings-settings-close-btn"><img src="${pluginPath}/images/close.webp"></div>
                                <h2>Settings</h2>

                                <div>Beggining Hour:</div>
                                <div>
                                    <select id="bookings-settings-beginning-hour">
                                        ${beginningHourOptionsHTML}
                                    </select>
                                </div>

                                <div>End hour:</div>
                                <div>
                                    <select id="bookings-settings-end-hour">
                                        ${endHourOptionsHTML}
                                    </select>
                                </div>

                                <div>Public Calendar</div>
                                <div>
                                    <input type="checkbox" id="bookings-settings-public">
                                </div>

                                <div>Calendar Permalink:</div>
                                <div id="authorization-token">
                                    <span id="refresh-authorization-token-btn"><img src="${pluginPath}/images/reset.webp"></span>
                                    <input type="text" value="https://${bookingsData.host}/request-an-appointment/?token=${authorizationToken}">
                                    <span id="copy-calendar-link"><img src="${pluginPath}/images/copy.webp"></span>
                                </div>

                                <h2>Booking Types</h2>

                                <div id="booking-types">
                                    ${bookingsTypesHTML}
                                </div>
                                
                                <div class="booking-type" id="booking-type-add">
                                    <input type="text" placeholder="New type">
                                    <span><img src="${pluginPath}/images/add.webp"></span>
                                </div>

                                <div id="booking-settings-save-changes-btn">
                                    <input type="button" value="Save Changes">
                                    <div><img src="${pluginPath}/images/loading.gif" id="bookings-settings-loader"></div>
                                </div>
                            </div>
                         </div>`;
    bookingsAdminContainer.innerHTML = bookingsAdminHTML;
    
    if (bookingsData.bookings_types) {
        bookingsData.bookings_types.forEach(type=>{
            const typeInput = document.querySelector(`#type-${type.type_id} input[type=text]`);
            const removeBtn = document.querySelector(`#type-${type.type_id} > span > img`);
            const undoRemoveBtn = document.querySelector(`#undo-remove-${type.type_id}`);
            typeInput.addEventListener('blur', ()=>{
                updateType(type.type_id);
            });
            removeBtn.addEventListener('pointerup', ()=>{
                prepareTypeForRemoval(type.type_id);
            });
            undoRemoveBtn.addEventListener('pointerup', ()=>{
                undoPrepareRemoval(type.type_id);
            });
        });
    }

    const bookingTypeAdd = document.querySelector('#booking-type-add input[type=text]');
    bookingTypeAdd.addEventListener('keydown', function(event) {
        let bookingsSettingsWindow = document.querySelector('#bookings-settings-window');
        if (event.key === 'Enter' || event.keyCode === 13) {
            event.preventDefault();
            this.blur();
            bookingsSettingsWindow.scrollTop = bookingsSettingsWindow.scrollHeight;
        }
    });

    const bookingsSettingsStartHourSelect = document.querySelector('#bookings-settings-beginning-hour');
    bookingsSettingsStartHourSelect.addEventListener('change', (event)=>{
        selectId = event.currentTarget.id
        setHour(selectId, 'start')
    });

    const bookingsSettingsEndHourSelect = document.querySelector('#bookings-settings-end-hour');
    bookingsSettingsEndHourSelect.addEventListener('change', (event)=>{
        selectId = event.currentTarget.id
        setHour(selectId, 'end')
    });

    const publicCalendarChk = document.querySelector('#bookings-settings-public');
    publicCalendarChk.addEventListener('change', (event)=>{
        chkId = event.currentTarget.id;
        togglePublicCalendarChk(chkId);
    });
    if (publicCalendar) {
        publicCalendarChk.checked = true;
        const authorizationTokenEle = document.querySelector('#authorization-token');
        authorizationTokenEle.style.opacity = '0.3';
        authorizationTokenEle.style.pointerEvents = 'none';
    }
    
    const refreshTokenLinkBtn = document.querySelector('#refresh-authorization-token-btn');
    refreshTokenLinkBtn.addEventListener('pointerup', refreshTokenLink);

    const copyCalendarLinkBtn = document.querySelector('#copy-calendar-link');
    copyCalendarLinkBtn.addEventListener('pointerup', copyCalendarLink);

    const addTypeInput = document.querySelector(`#booking-type-add input[type=text]`);
    addTypeInput.addEventListener('blur', addBookingTypeBlock);

    const saveAdminChangesBtn = document.querySelector(`#booking-settings-save-changes-btn input[type=button]`);
    saveAdminChangesBtn.addEventListener('pointerup', saveBookingAdminChanges);

}

function generateHourOptions(selectedValue) {
    const options = [];
    for (let i = 0; i < 24; i++) {
        const hour12 = i % 12 === 0 ? 12 : i % 12;
        const period = i < 12 ? 'am' : 'pm';
        const display = `${hour12}:00 ${period}`;
        const selected = i === selectedValue ? ' selected' : '';
        options.push(`<option value="${i}"${selected}>${display}</option>`);
    }
    return options.join('\n');
}

function addBookingTypeBlock() {
    const bookingsAdminData = getBookingsAdminData();
    const timestamp = Date.now();
    const typeTitleInput = document.querySelector(`#${this.parentElement.id} input[type=text]`);
    const typeTitle = typeTitleInput.value;
    if (typeTitle === "") return;
    const bookingTypesContainer = document.querySelector('#booking-types');

    const newTypeDiv = document.createElement('div');
    newTypeDiv.classList.add('booking-type');
    newTypeDiv.id = `new-type-${timestamp}`;
    
    const deleteSpan = document.createElement('span');
    const deleteImg = document.createElement('img');
    deleteSpan.classList.add('remove-type');
    deleteImg.src = `${bookingsData.plugin_path}/images/close.webp`;
    deleteSpan.appendChild(deleteImg);

    const plusSpan = document.createElement('span');
    const plusImg = document.createElement('img');
    plusSpan.classList.add('undo-remove');
    plusImg.src = `${bookingsData.plugin_path}/images/add.webp`;
    plusSpan.appendChild(plusImg);

    const input = document.createElement('input');
    input.type = 'text';
    input.value = typeTitle;
    input.addEventListener('blur', ()=>{
        updateType(timestamp, true);
    });

    newTypeDiv.appendChild(deleteSpan);
    newTypeDiv.appendChild(input);
    newTypeDiv.appendChild(plusSpan);

    bookingTypesContainer.appendChild(newTypeDiv);

    bookingsAdminData.types[`new-type-${timestamp}`] = { title: typeTitle };
    updateAdminData(bookingsAdminData);
    typeTitleInput.value = '';

    deleteSpan.addEventListener('pointerup', () => {
        newTypeDiv.remove();
        delete bookingsAdminData.types[`new-type-${timestamp}`];
        window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
    });

    enableAdminChangesBtn();
}

function setHour(selectId, type) {
    const selectEle = document.querySelector(`#${selectId}`);
    const selectValue = selectEle.value;
    const bookingsAdminData = getBookingsAdminData();
    if (type === 'start') bookingsAdminData.settings.beginning_hour = parseInt(selectValue);
    if (type === 'end') bookingsAdminData.settings.end_hour = parseInt(selectValue);
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
    enableAdminChangesBtn();
}

function updateType(typeId, newType = false) {
    let newStr = '';
    if (newType) newStr = 'new-';
    const bookingsAdminData = getBookingsAdminData();
    const typeInput = document.querySelector(`#${newStr}type-${typeId} input[type=text]`);
    bookingsAdminData.types[`${newStr}type-${typeId}`].title = typeInput.value;
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
    enableAdminChangesBtn();
}

function prepareTypeForRemoval(typeId) {
    const bookingsAdminData = getBookingsAdminData();
    const typeInput = document.querySelector(`#type-${typeId} input`);
    const typeRemoveBtn = document.querySelector(`#type-${typeId} > span > img`);
    const undoRemoveBtn = document.querySelector(`#undo-remove-${typeId}`);

    typeInput.style.opacity = '0.3';
    typeInput.style.pointerEvents = 'none';
    typeRemoveBtn.style.opacity = '0.3';
    typeRemoveBtn.style.pointerEvents = 'none';
    undoRemoveBtn.style.visibility = 'visible';

    bookingsAdminData.types[`type-${typeId}`].remove = true;
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));

    enableAdminChangesBtn();
}

function undoPrepareRemoval(typeId) {
    const bookingsAdminData = getBookingsAdminData();
    const typeInput = document.querySelector(`#type-${typeId} input`);
    const typeRemoveBtn = document.querySelector(`#type-${typeId} > span > img`);
    const undoRemoveBtn = document.querySelector(`#undo-remove-${typeId}`);

    typeInput.style.opacity = '1';
    typeInput.style.pointerEvents = 'auto';
    typeRemoveBtn.style.opacity = '1';
    typeRemoveBtn.style.pointerEvents = 'auto';
    undoRemoveBtn.style.visibility = 'hidden';

    delete bookingsAdminData.types[`type-${typeId}`].remove;
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
}

function copyCalendarLink() {
    const authorizationTokenInput = document.querySelector('#authorization-token input');

    authorizationTokenInput.select();
    authorizationTokenInput.setSelectionRange(0, authorizationTokenInput.value.length);

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(authorizationTokenInput.value).then(function() {
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    } else {
        try {
            const successful = document.execCommand('copy');
            if (!successful) {
                console.error('Copy command was unsuccessful');
            }
        } catch (err) {
            console.error('Unable to copy', err);
        }
    }
}

function togglePublicCalendarChk(chkId) {
    const publicCalendarChkEle = document.querySelector(`#${chkId}`);
    const bookingsAdminData = getBookingsAdminData();
    const authorizationTokenEle = document.querySelector('#authorization-token');
    bookingsAdminData.settings.public_calendar = publicCalendarChkEle.checked;
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
    authorizationTokenEle.style.opacity = '0.3';
    authorizationTokenEle.style.pointerEvents = 'none';
    if (bookingsAdminData.settings.authorization_token) {
        delete bookingsAdminData.settings.authorization_token;
        window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
    }
    if (!publicCalendarChkEle.checked) {
        authorizationTokenEle.style.opacity = '1';
        authorizationTokenEle.style.pointerEvents = 'auto';
    }
    enableAdminChangesBtn();
}

function refreshTokenLink() {
    const authorizationTokenInput = document.querySelector('#authorization-token input');
    const bookingsAdminData = getBookingsAdminData();
    let token = generateToken();
    authorizationTokenInput.value = 'generating token...';
    setTimeout(function() {
        authorizationTokenInput.value = `https://${bookingsData.host}/request-an-appointment/?token=${token}`;
    }, 500);

    bookingsAdminData.settings.authorization_token = token;
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));

    enableAdminChangesBtn();
}

function generateToken(length = 21) {
    const array = new Uint8Array(length);
    window.crypto.getRandomValues(array);
    // Convert bytes to characters using a URL-safe Base64 encoding
    const token = Array.from(array, byte => {
        // Map byte to a URL-safe character
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        return chars[byte % chars.length];
    }).join('');
    return token;
}

function openBookingsSettings() {
    const body = document.querySelector('body');
    const bookingsWindow = document.querySelector('#bookings-settings-window');
    
    body.style.overflow = 'hidden';
    bookingsBackDrop.style.display = 'block';
    bookingsWindow.style.display = 'block';
}

function closeBookingsSettings() {
    const body = document.querySelector('body');
    const bookingsWindow = document.querySelector('#bookings-settings-window');
    
    body.style.overflow = 'auto';
    bookingsBackDrop.style.display = 'none';
    bookingsWindow.style.display = 'none';
}

function enableAdminChangesBtn() {
    const saveAdminChangesBtn = document.querySelector(`#booking-settings-save-changes-btn input[type=button]`);
    saveAdminChangesBtn.style.pointerEvents = 'auto';
    saveAdminChangesBtn.style.opacity = '1';
}

function disableAdminChangesBtn() {
    const saveAdminChangesBtn = document.querySelector(`#booking-settings-save-changes-btn input[type=button]`);
    saveAdminChangesBtn.style.pointerEvents = 'auto';
    saveAdminChangesBtn.style.opacity = '0.3';
}

function getBookingsAdminData() {
    return JSON.parse(window.sessionStorage.getItem('ffc-bookings-data'));
}

function updateAdminData(data) {
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(data));
}

function saveBookingsAdminData() {
    if (window.sessionStorage.getItem('ffc-bookings-data') === null) window.sessionStorage.setItem('ffc-bookings-data', '{}');
    const bookingsAdminData = getBookingsAdminData();
    if (!bookingsAdminData.hasOwnProperty('settings')) bookingsAdminData.settings = {};
    const adminSettings = calData.admin_settings; 
    if (adminSettings) bookingsAdminData.settings = adminSettings;
    bookingsAdminData.types = {};
    if (bookingsData.bookings_types !== null) {
        bookingsData.bookings_types.forEach(type=>{
            bookingsAdminData.types[`type-${type.type_id}`] = {
                id: parseInt(type.type_id),
                title: type.type_title
            }
        });
    }
    window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingsAdminData));
}

function saveBookingAdminChanges() {
    const bookingAdminData = getBookingsAdminData();
    const adminData = JSON.stringify(bookingAdminData);
    const saveChangesLoader = document.querySelector(`#bookings-settings-loader`);
    saveChangesLoader.style.visibility = 'visible';
    fetch(`${bookingsData.api_url}save-bookings-admin-data`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': bookingsData.nonce
        },
        body: adminData
    })
    .then(response => response.json())
    .then(function (data) {
        if (data.success) {
            data.added_types.forEach(type=>{
                if (bookingAdminData.types[`new-type-${type.timestamp}`]) {
                    let title = bookingAdminData.types[`new-type-${type.timestamp}`].title;
                    delete bookingAdminData.types[`new-type-${type.timestamp}`];
                    bookingAdminData.types[`type-${type.id}`] = {
                        id: type.id,
                        title: title
                    };
                }
            });
            saveChangesLoader.style.visibility = 'hidden';
            data.deleted_types.forEach(typeId=>{
                const typeBlock = document.querySelector(`#type-${typeId}`);
                typeBlock.remove();
                delete bookingAdminData.types[`type-${typeId}`];
            });
            window.sessionStorage.setItem('ffc-bookings-data', JSON.stringify(bookingAdminData));
            disableAdminChangesBtn();
        }
        if (!data.success) console.error(data.message);
    })
    .catch(function (error) {
        console.error('Error:', error);
    });
}