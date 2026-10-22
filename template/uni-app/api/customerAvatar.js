// The actual named avatar route. IDs stay canonical decimal strings end to end.
export const CUSTOMER_AVATAR_UPLOAD = 'user/avatar/files';

export function avatarUploadSelection(response) {
    const data = response && response.data;
    if (!data || typeof data !== 'object' || Array.isArray(data) ||
        typeof data.avatar_asset_id !== 'string' ||
        !/^[1-9][0-9]{0,19}$/.test(data.avatar_asset_id) ||
        (data.avatar_asset_id.length === 20 && data.avatar_asset_id > '18446744073709551615') ||
        typeof data.url !== 'string' || !data.url ||
        typeof data.attachment_ref !== 'string' || !data.attachment_ref ||
        typeof data.name !== 'string' || !data.name) return null;
    return { avatar_asset_id: data.avatar_asset_id, url: data.url,
        attachment_ref: data.attachment_ref, name: data.name };
}
