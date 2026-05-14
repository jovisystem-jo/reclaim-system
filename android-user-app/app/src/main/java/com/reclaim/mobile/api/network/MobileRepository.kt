package com.reclaim.mobile.api.network

import android.content.Context
import android.net.Uri
import com.reclaim.mobile.models.Claim
import com.reclaim.mobile.models.ClaimsEnvelope
import com.reclaim.mobile.models.DashboardEnvelope
import com.reclaim.mobile.models.Item
import com.reclaim.mobile.models.ItemListEnvelope
import com.reclaim.mobile.models.NotificationsEnvelope
import com.reclaim.mobile.models.OptionsEnvelope
import com.reclaim.mobile.models.ProfileEnvelope
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File

class MobileRepository(private val context: Context) {
    private val api = ApiClient.service(context)

    suspend fun dashboard(): Result<DashboardEnvelope> = runCatching {
        val response = api.dashboard()
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun items(scope: String, query: String, status: String?): Result<ItemListEnvelope> = runCatching {
        val response = api.items(scope = scope, query = query.ifBlank { null }, status = status?.ifBlank { null })
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun itemDetail(itemId: Int): Result<Item> = runCatching {
        val response = api.itemDetail(itemId)
        response.data?.item ?: throw IllegalStateException(response.message)
    }

    suspend fun reportItem(
        title: String,
        category: String,
        brand: String,
        color: String,
        description: String,
        location: String,
        dateOccurred: String,
        timeOccurred: String,
        deliveryOption: String,
        status: String,
        imageUri: Uri?
    ): Result<Item> = runCatching {
        val imagePart = imageUri?.let { uriToMultipart("image", it) }
        val response = api.reportItem(
            title = title.toTextPart(),
            category = category.toTextPart(),
            brand = brand.toTextPart(),
            color = color.toTextPart(),
            description = description.toTextPart(),
            location = location.toTextPart(),
            dateOccurred = dateOccurred.toTextPart(),
            timeOccurred = timeOccurred.toTextPart(),
            deliveryOption = deliveryOption.toTextPart(),
            status = status.toTextPart(),
            image = imagePart
        )
        response.data?.item ?: throw IllegalStateException(response.message)
    }

    suspend fun claims(): Result<ClaimsEnvelope> = runCatching {
        val response = api.claims()
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun submitClaim(itemId: Int, description: String): Result<Claim> = runCatching {
        val response = api.submitClaim(itemId, description)
        response.data?.claim ?: throw IllegalStateException(response.message)
    }

    suspend fun cancelClaim(claimId: Int): Result<Unit> = runCatching {
        val response = api.cancelClaim(claimId)
        if (!response.success) {
            throw IllegalStateException(response.message)
        }
    }

    suspend fun completeClaim(claimId: Int): Result<Unit> = runCatching {
        val response = api.completeClaim(claimId)
        if (!response.success) {
            throw IllegalStateException(response.message)
        }
    }

    suspend fun notifications(): Result<NotificationsEnvelope> = runCatching {
        val response = api.notifications()
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun markNotificationRead(notificationId: Int): Result<Unit> = runCatching {
        val response = api.markNotificationRead(notificationId)
        if (!response.success) {
            throw IllegalStateException(response.message)
        }
    }

    suspend fun markAllNotificationsRead(): Result<Unit> = runCatching {
        val response = api.markAllNotificationsRead()
        if (!response.success) {
            throw IllegalStateException(response.message)
        }
    }

    suspend fun profile(): Result<ProfileEnvelope> = runCatching {
        val response = api.profile()
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun updateProfile(
        name: String,
        phone: String,
        department: String,
        studentStaffId: String,
        currentPassword: String,
        newPassword: String
    ): Result<ProfileEnvelope> = runCatching {
        val response = api.updateProfile(name, phone, department, studentStaffId, currentPassword, newPassword)
        response.data ?: throw IllegalStateException(response.message)
    }

    suspend fun options(): Result<OptionsEnvelope> = runCatching {
        val response = api.options()
        response.data ?: throw IllegalStateException(response.message)
    }

    private fun String.toTextPart(): RequestBody = toRequestBody("text/plain".toMediaType())

    private fun uriToMultipart(partName: String, uri: Uri): MultipartBody.Part {
        val file = FileUtils.copyUriToCache(context, uri)
        val body = file.asRequestBody("image/*".toMediaType())
        return MultipartBody.Part.createFormData(partName, file.name, body)
    }
}
