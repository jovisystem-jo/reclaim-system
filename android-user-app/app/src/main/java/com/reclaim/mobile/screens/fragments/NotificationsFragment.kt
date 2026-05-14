package com.reclaim.mobile.screens.fragments

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.google.android.material.button.MaterialButton
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.screens.adapters.NotificationAdapter
import kotlinx.coroutines.launch

class NotificationsFragment : Fragment() {
    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        return inflater.inflate(R.layout.fragment_notifications, container, false)
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val repository = MobileRepository(requireContext())
        val buttonMarkAll: MaterialButton = view.findViewById(R.id.buttonMarkAllRead)
        val progress: ProgressBar = view.findViewById(R.id.progressNotifications)
        val empty: TextView = view.findViewById(R.id.textNotificationsEmpty)
        val recycler: RecyclerView = view.findViewById(R.id.recyclerNotifications)
        val swipe: SwipeRefreshLayout = view.findViewById(R.id.swipeNotifications)

        val adapter = NotificationAdapter { notification ->
            lifecycleScope.launch {
                repository.markNotificationRead(notification.id)
                    .onSuccess {
                        loadNotifications(repository, adapter, progress, empty, swipe)
                    }
                    .onFailure {
                        Toast.makeText(requireContext(), ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    }
            }
        }

        recycler.layoutManager = LinearLayoutManager(requireContext())
        recycler.adapter = adapter

        buttonMarkAll.setOnClickListener {
            lifecycleScope.launch {
                repository.markAllNotificationsRead()
                    .onSuccess {
                        loadNotifications(repository, adapter, progress, empty, swipe)
                    }
                    .onFailure {
                        Toast.makeText(requireContext(), ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    }
            }
        }

        swipe.setOnRefreshListener {
            loadNotifications(repository, adapter, progress, empty, swipe)
        }

        loadNotifications(repository, adapter, progress, empty, swipe)
    }

    private fun loadNotifications(
        repository: MobileRepository,
        adapter: NotificationAdapter,
        progress: ProgressBar,
        empty: TextView,
        swipe: SwipeRefreshLayout
    ) {
        progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            repository.notifications()
                .onSuccess { response ->
                    adapter.submitList(response.notifications)
                    empty.visibility = if (response.notifications.isEmpty()) View.VISIBLE else View.GONE
                }
                .onFailure {
                    empty.visibility = View.VISIBLE
                    empty.text = ApiErrorParser.message(it)
                }

            progress.visibility = View.GONE
            swipe.isRefreshing = false
        }
    }
}
