package com.reclaim.mobile.screens.adapters

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.reclaim.mobile.R
import com.reclaim.mobile.models.AppNotification

class NotificationAdapter(
    private val onMarkRead: (AppNotification) -> Unit
) : RecyclerView.Adapter<NotificationAdapter.NotificationViewHolder>() {

    private val notifications = mutableListOf<AppNotification>()

    fun submitList(newNotifications: List<AppNotification>) {
        notifications.clear()
        notifications.addAll(newNotifications)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): NotificationViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.row_notification, parent, false)
        return NotificationViewHolder(view, onMarkRead)
    }

    override fun onBindViewHolder(holder: NotificationViewHolder, position: Int) {
        holder.bind(notifications[position])
    }

    override fun getItemCount(): Int = notifications.size

    class NotificationViewHolder(
        itemView: View,
        private val onMarkRead: (AppNotification) -> Unit
    ) : RecyclerView.ViewHolder(itemView) {
        private val title: TextView = itemView.findViewById(R.id.textNotificationTitle)
        private val message: TextView = itemView.findViewById(R.id.textNotificationMessage)
        private val time: TextView = itemView.findViewById(R.id.textNotificationTime)
        private val markRead: MaterialButton = itemView.findViewById(R.id.buttonNotificationRead)

        fun bind(notification: AppNotification) {
            title.text = notification.title
            message.text = notification.message
            time.text = notification.timeAgo ?: notification.createdAt ?: ""
            markRead.visibility = if (notification.isRead) View.GONE else View.VISIBLE
            markRead.setOnClickListener { onMarkRead(notification) }
        }
    }
}
